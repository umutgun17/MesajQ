<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailTemplate;
use App\Models\MarketingJourney;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sipariş Bilgilendirme otomasyonu — sipariş alındı / kargoya verildi / teslim edildi
 * aşamalarında müşteriye İŞLEMSEL (utility) bildirim gönderir (WhatsApp / SMS / e-posta).
 *
 * DOĞRULUK GARANTİLERİ:
 *  - Her sipariş için journey-state tutulur (marketing_journeys, automation=order_status,
 *    order_id unique) → aynı aşama bildirimi ASLA iki kez gitmez (meta.notified_stages).
 *  - Statü ATLANMAZ: senkronlar arasında sipariş birden fazla aşama ilerlediyse, bildirilmemiş
 *    TÜM aşamalar sırayla gönderilir (placed → shipped → delivered).
 *  - GERİYE DÖNÜK SPAM YOK: otomasyon açıldığında geçmiş siparişler SESSİZCE baseline alınır
 *    (mevcut aşamaları bildirilmiş sayılır); yalnızca taze siparişler (order_date son
 *    FRESH_HOURS içinde) ve baseline SONRASI geçişler bildirilir.
 *  - İptal/iade/mağaza statülerinde journey durdurulur, hiçbir bildirim gitmez.
 *  - WhatsApp şablonu Meta onayı beklerken (132001) aşama KAYBOLMAZ → onaylanınca otomatik
 *    gönderilir. Diğer kalıcı hatalarda kanal başına en çok MAX_ATTEMPTS deneme yapılır.
 *  - İşlemsel mesaj olduğundan kapılar atlanır (skip_gates): sipariş onayı/kargo bildirimi
 *    müşterinin BEKLEDİĞİ mesajdır, saat penceresi/frekans limiti uygulanmaz.
 */
class OrderStatusNotifier
{
    public const KEY = 'order_status';

    /** Sipariş bu süreden eskiyse İLK görüşte sessiz baseline (bildirim yok). */
    public const FRESH_HOURS = 2;

    /** Senkron gecikmelerini karşılayan tarama penceresi. */
    public const LOOKBACK_HOURS = 6;

    /** Kanal başına aşama gönderim deneme sınırı (132001 hariç). */
    public const MAX_ATTEMPTS = 3;

    /** Aşamalar — sıra önemli (atlama olursa bu sırayla tamamlanır). */
    public const STAGES = ['placed', 'shipped', 'delivered'];

    /** Bu Ticimax statülerinde bildirim akışı DURUR (iptal/iade/mağaza/teslim edilemedi). */
    public const STOP_STATUSES = [8, 9, 11, 12, 13, 15, 16, 17, 18, 19, 20, 21];

    /**
     * Bildirim YALNIZ kendi kanallarımızdan gelen siparişlere gider. Pazaryeri
     * (Temu/N11/PAZARAMA/Trendyol...) sipariş verisindeki telefon alıcıya ait
     * olmayabilir (Temu vakası: "Ben Mehmet Karakaya değilim" şikayeti) ve
     * pazaryerleri kendi bildirimini zaten atar. settings 'order_notify_sources'
     * ile tenant bazında değiştirilebilir.
     */
    public const OWN_SOURCES = ['TicimaxWeb', 'NativeAndroid', 'NativeIOS'];

    public function __construct(protected UnifiedSender $sender) {}

    /** @return array<string,mixed> */
    protected function settings(int $tenantId): array
    {
        return Tenant::withoutGlobalScopes()->find($tenantId)?->settings ?? [];
    }

    public function isEnabled(int $tenantId): bool
    {
        return ! empty($this->settings($tenantId)['order_notify_enabled']);
    }

    /** @return array{email:bool,sms:bool,whatsapp:bool} */
    public function channels(int $tenantId): array
    {
        $c = $this->settings($tenantId)['order_notify_channels'] ?? [];

        return [
            'email' => (bool) ($c['email'] ?? false),
            'sms' => (bool) ($c['sms'] ?? false),
            'whatsapp' => (bool) ($c['whatsapp'] ?? true),
        ];
    }

    /** @return array{placed:bool,shipped:bool,delivered:bool} */
    public function stagesEnabled(int $tenantId): array
    {
        $s = $this->settings($tenantId)['order_notify_stages'] ?? [];

        return [
            'placed' => (bool) ($s['placed'] ?? true),
            'shipped' => (bool) ($s['shipped'] ?? true),
            'delivered' => (bool) ($s['delivered'] ?? true),
        ];
    }

    /**
     * Siparişin şu anki aşaması. 'stop' = akış durdurulacak statü, null = henüz aşamada değil
     * (ödeme görülmemiş status=0/3 → terk/ödeme bekliyor; bildirim yok).
     */
    public function stageOf(Order $order): ?string
    {
        $status = (int) $order->status;

        if (in_array($status, self::STOP_STATUSES, true)) {
            return 'stop';
        }
        if ($status === 7) {
            return 'delivered';
        }
        if ($status === 6) {
            return 'shipped';
        }
        // "Sipariş alındı" = ödeme görüldü (status=0'da paid) veya hazırlık statüsü (4/5)
        if ((int) $order->payment_status === 1 || (float) $order->paid_amount > 0
            || in_array($status, [4, 5], true)) {
            return 'placed';
        }

        return null;
    }

    /** Aşamanın STAGES içindeki sırası (karşılaştırma için). */
    protected function stageRank(?string $stage): int
    {
        $i = array_search($stage, self::STAGES, true);

        return $i === false ? -1 : $i;
    }

    /**
     * Ana döngü: son LOOKBACK saatte güncellenen siparişleri tara, aşama geçişlerini bildir.
     *
     * @return array{scanned:int,sent:int,baselined:int,stopped:int,skipped:int,simulated:bool}
     */
    public function run(int $tenantId, bool $live = false, int $limit = 2000): array
    {
        $simulate = ! ($live && $this->isEnabled($tenantId));
        $channels = $this->channels($tenantId);
        $stagesEnabled = $this->stagesEnabled($tenantId);

        $ownSources = (array) ($this->settings($tenantId)['order_notify_sources'] ?? self::OWN_SOURCES);

        $orders = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('updated_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->whereIn('order_source', $ownSources)
            ->where(function ($w) {
                // Yalnızca aşamaya girmiş veya durdurulacak siparişler (terk sepetleri eleme)
                $w->where('payment_status', 1)
                    ->orWhere('paid_amount', '>', 0)
                    ->orWhereIn('status', array_merge([4, 5, 6, 7], self::STOP_STATUSES));
            })
            // EN YENİ ÖNCE: safety/status sync binlerce eski siparişin updated_at'ını
            // topluca bump'lıyor; ASC sıralamada taze siparişler 2000 limitine giremiyor ve
            // 07-13 TR arası "sipariş alındı" bildirimi kayboluyordu (2026-09-21 tespiti).
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $sent = 0; $baselined = 0; $stopped = 0; $skipped = 0;

        foreach ($orders as $order) {
            $stage = $this->stageOf($order);
            if ($stage === null) {
                continue;
            }

            $journey = MarketingJourney::withoutGlobalScopes()->firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'automation' => self::KEY,
                    'order_id' => $order->id,
                ],
                [
                    'customer_id' => $order->customer_id,
                    'recipient_email' => $order->customer_email,
                    'recipient_phone' => $this->orderPhone($order),
                    'status' => 'active',
                    'current_step' => 0,
                    'entered_at' => $order->order_date ?? now(),
                    'next_action_at' => null,
                    'meta' => [
                        'order_no' => $order->order_no,
                        'notified_stages' => [],
                        'attempts' => [],
                    ],
                ]
            );

            // Kapanmış akışa dokunma
            if (in_array($journey->status, ['stopped', 'exhausted'], true)) {
                continue;
            }

            // YARIŞ KİLİDİ: aynı journey'yi eşzamanlı iki koşucu (cron + manuel çalıştırma)
            // birlikte işleyip aynı aşamayı İKİ KEZ göndermesin (2026-07-10: 33 mükerrer
            // bildirimin kökü buydu). Kilit alınamazsa diğer koşucu işliyordur → atla.
            $locked = (bool) (DB::selectOne('select pg_try_advisory_lock(83251, ?) as ok', [$journey->id])->ok ?? false);
            if (! $locked) {
                $skipped++;
                continue;
            }

            try {
            // Kilit altında güncel durumu yeniden oku (diğer koşucu az önce işlemiş olabilir)
            $journey->refresh();
            if (in_array($journey->status, ['stopped', 'exhausted'], true)) {
                continue;
            }

            // İptal/iade → akışı durdur, bildirim yok
            if ($stage === 'stop') {
                $journey->update(['status' => 'stopped', 'stopped_reason' => 'order_' . $order->status]);
                $stopped++;
                continue;
            }

            $meta = $journey->meta ?? [];
            $notified = $meta['notified_stages'] ?? [];

            // İLK GÖRÜŞ + eski sipariş → sessiz baseline (geriye dönük bildirim SPAM'i yok)
            if ($journey->wasRecentlyCreated) {
                $orderAge = Carbon::parse($order->order_date ?? $order->created_at)->diffInHours(now());
                if ($orderAge > self::FRESH_HOURS) {
                    foreach (self::STAGES as $s) {
                        if ($this->stageRank($s) <= $this->stageRank($stage)) {
                            $notified[$s] = 'baseline';
                        }
                    }
                    $meta['notified_stages'] = $notified;
                    $journey->update([
                        'meta' => $meta,
                        'status' => $stage === 'delivered' ? 'exhausted' : 'active',
                    ]);
                    $baselined++;
                    continue;
                }
            }

            // Bildirilmemiş aşamaları SIRAYLA gönder (statü atlanmaz)
            $dirty = false;
            $journeySent = false;
            foreach (self::STAGES as $s) {
                if ($this->stageRank($s) > $this->stageRank($stage)) {
                    break; // henüz ulaşılmamış aşama
                }
                if (isset($notified[$s])) {
                    continue; // zaten bildirildi / baseline
                }
                if (! $stagesEnabled[$s]) {
                    $notified[$s] = 'stage_disabled';
                    $dirty = true;
                    continue;
                }

                if ($simulate) {
                    $skipped++;
                    continue;
                }

                $result = $this->notifyStage($tenantId, $journey, $order, $s, $channels, $meta);
                $meta = $result['meta'];
                $notified = $meta['notified_stages'] ?? [];
                $dirty = true;

                if ($result['status'] === 'sent') {
                    $sent++;
                    $journeySent = true;
                } elseif ($result['status'] === 'defer') {
                    // Şablon onayı bekleniyor / geçici hata → sonraki çalışmada yeniden dene;
                    // SONRAKI aşamalara geçme (sıra bozulmasın)
                    $skipped++;
                    break;
                } else {
                    $skipped++;
                }
            }

            if ($dirty) {
                $journey->update([
                    'meta' => $meta,
                    'current_step' => count(array_filter($notified, fn ($v) => $v !== 'stage_disabled')),
                    'last_sent_at' => $journeySent ? now() : $journey->last_sent_at,
                    'status' => isset($notified['delivered']) ? 'exhausted' : 'active',
                ]);
            }
            } finally {
                DB::selectOne('select pg_advisory_unlock(83251, ?) as ok', [$journey->id]);
            }
        }

        return [
            'scanned' => $orders->count(),
            'sent' => $sent,
            'baselined' => $baselined,
            'stopped' => $stopped,
            'skipped' => $skipped,
            'simulated' => $simulate,
        ];
    }

    /**
     * Tek aşamayı, açık olan TÜM kanallardan gönderir.
     * Dönüş status: sent (en az bir kanal gitti) | defer (yeniden denenecek) | failed | no_channel.
     *
     * @return array{status:string, meta:array<string,mixed>}
     */
    protected function notifyStage(int $tenantId, MarketingJourney $journey, Order $order, string $stage, array $channels, array $meta): array
    {
        $notified = $meta['notified_stages'] ?? [];
        $attempts = $meta['attempts'] ?? [];
        $anySent = false;
        $anyDefer = false;

        foreach (['whatsapp', 'sms', 'email'] as $channel) {
            if (empty($channels[$channel])) {
                continue;
            }

            $to = $channel === 'email' ? $journey->recipient_email : ($journey->recipient_phone ?: $this->orderPhone($order));
            if (! $to) {
                continue;
            }

            $attemptKey = "{$stage}.{$channel}";
            if (($attempts[$attemptKey] ?? 0) >= self::MAX_ATTEMPTS) {
                continue; // bu kanal için pes edildi (132001 sayaç artırmaz)
            }

            $message = $this->buildMessage($tenantId, $stage, $order, $channel);
            if ($message === null) {
                continue;
            }

            $log = $this->sender->send($tenantId, $channel, (string) $to, $message, [
                'source' => self::KEY,
                'source_type' => 'automation',
                'customer_id' => $journey->customer_id,
                // İşlemsel bildirim: müşterinin beklediği mesaj → saat penceresi/frekans yok
                'skip_gates' => true,
                'meta' => [
                    'journey_id' => $journey->id,
                    'stage' => $stage,
                    'order_no' => $order->order_no,
                    'customer_name' => trim((string) $order->customer_name),
                    'cargo_company' => $order->cargo_company,
                    'cargo_tracking_no' => $order->cargo_tracking_no,
                ],
            ]);

            if ($log->status === 'sent') {
                $anySent = true;
            } elseif ($log->status === 'failed'
                && (str_contains((string) $log->error, '132001') || str_contains((string) $log->error, '132000'))) {
                // WhatsApp şablonu Meta onayında (132001) veya param uyumsuz (132000, kod
                // düzeltilince akar) → aşamayı KAYBETME, sonraki çalışmada dene
                $anyDefer = true;
            } else {
                $attempts[$attemptKey] = ($attempts[$attemptKey] ?? 0) + 1;
            }
        }

        if ($anySent) {
            $notified[$stage] = now()->toIso8601String();
        } elseif (! $anyDefer) {
            // Hiçbir kanal gönderemedi ve erteleme de yok → deneme hakkı bitenleri işaretle
            $exhaustedAll = true;
            foreach (['whatsapp', 'sms', 'email'] as $channel) {
                if (empty($channels[$channel])) {
                    continue;
                }
                if (($attempts["{$stage}.{$channel}"] ?? 0) < self::MAX_ATTEMPTS) {
                    $exhaustedAll = false;
                }
            }
            if ($exhaustedAll) {
                $notified[$stage] = 'failed';
            }
        }

        $meta['notified_stages'] = $notified;
        $meta['attempts'] = $attempts;

        $status = $anySent ? 'sent' : ($anyDefer ? 'defer' : (isset($notified[$stage]) ? 'failed' : 'defer'));

        return ['status' => $status, 'meta' => $meta];
    }

    /**
     * Aşama + kanal için mesaj içeriği.
     * WhatsApp → Meta onaylı UTILITY şablon; SMS → kısa metin; e-posta → seed HTML şablonu.
     */
    protected function buildMessage(int $tenantId, string $stage, Order $order, string $channel): ?array
    {
        $s = $this->settings($tenantId);
        $name = trim((string) $order->customer_name) ?: 'Değerli müşterimiz';
        $orderNo = (string) $order->order_no;
        $total = number_format((float) $order->total_amount, 2, ',', '.') . ' TL';
        $cargo = trim((string) $order->cargo_company) ?: 'kargo';
        $trackNo = trim((string) $order->cargo_tracking_no);
        $trackUrl = trim((string) $order->cargo_tracking_url);
        $shop = (string) (Tenant::withoutGlobalScopes()->find($tenantId)?->name ?? '');

        if ($channel === 'whatsapp') {
            $tplNames = $s['order_notify_wa_templates'] ?? [];
            $defaults = [
                'placed' => 'siparis_alindi',
                'shipped' => 'siparis_kargoda',
                'delivered' => 'siparis_teslim_edildi',
            ];
            $params = match ($stage) {
                'placed' => [$name, $orderNo, $total],
                'shipped' => [$name, $orderNo, $cargo, $trackNo !== '' ? $trackNo : '-'],
                'delivered' => [$name, $orderNo],
            };

            $template = [
                'name' => (string) ($tplNames[$stage] ?? $defaults[$stage]),
                'lang' => 'tr',
                'params' => $params,
            ];
            // Kargo aşaması: "Kargomu Takip Et" dinamik URL butonu — şablondaki
            // https://suratkargo.com.tr/KargoTakip/?kargotakipno={{1}} son eki = SİPARİŞ NO
            // (Sürat, TB'li sipariş referansıyla takip destekliyor; order_no her siparişte var).
            if ($stage === 'shipped') {
                $template['url_button_param'] = $orderNo !== '' ? $orderNo : '-';
            }

            return [
                'template' => $template,
                'body' => $this->smsText($stage, $name, $orderNo, $total, $cargo, $trackNo, $trackUrl, $shop),
            ];
        }

        if ($channel === 'sms') {
            return ['body' => $this->smsText($stage, $name, $orderNo, $total, $cargo, $trackNo, $trackUrl, $shop), 'header' => $s['sms_header'] ?? null];
        }

        // email — seed şablonları (order_confirmation / shipping); teslim için kısa metin fallback
        $tplKeys = $s['order_notify_email_templates'] ?? [];
        $defaultKeys = ['placed' => 'order_confirmation', 'shipped' => 'shipping', 'delivered' => null];
        $key = $tplKeys[$stage] ?? $defaultKeys[$stage];
        $subjects = [
            'placed' => "Siparişiniz alındı — {$orderNo}",
            'shipped' => "Siparişiniz kargoda — {$orderNo}",
            'delivered' => "Siparişiniz teslim edildi — {$orderNo}",
        ];
        $vars = [
            'customer_name' => $name, 'shop_name' => $shop, 'order_no' => $orderNo,
            'order_total' => $total, 'cargo_company' => $cargo,
            'tracking_no' => $trackNo, 'tracking_url' => $trackUrl,
        ];
        $tpl = $key ? MarketingEmailTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('key', $key)->where('is_active', true)->first() : null;

        return [
            'subject' => $subjects[$stage],
            'html' => $tpl ? $tpl->render($vars)['html'] : null,
            'body' => $this->smsText($stage, $name, $orderNo, $total, $cargo, $trackNo, $trackUrl, $shop),
        ];
    }

    /** SMS/düz-metin gövdesi (log önizlemesi ve SMS kanalı için). */
    protected function smsText(string $stage, string $name, string $orderNo, string $total, string $cargo, string $trackNo, string $trackUrl, string $shop): string
    {
        return match ($stage) {
            'placed' => "{$shop}: {$orderNo} numarali siparisiniz alindi. Tutar: {$total}. Tesekkurler!",
            'shipped' => "{$shop}: {$orderNo} numarali siparisiniz {$cargo} ile kargoya verildi."
                . ($trackNo !== '' ? " Takip no: {$trackNo}" : '')
                . ($trackUrl !== '' ? " {$trackUrl}" : ''),
            'delivered' => "{$shop}: {$orderNo} numarali siparisiniz teslim edildi. Bizi tercih ettiginiz icin tesekkurler!",
        };
    }

    /** Sipariş telefonu: üye kaydı → fatura adresi → teslimat adresi. */
    protected function orderPhone(Order $order): ?string
    {
        $phone = trim((string) ($order->customer?->phone
            ?? ($order->billing_address['phone'] ?? ($order->shipping_address['phone'] ?? ''))));

        return $phone !== '' ? $phone : null;
    }
}
