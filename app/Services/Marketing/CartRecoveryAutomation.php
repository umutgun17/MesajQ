<?php

namespace App\Services\Marketing;

use App\Models\Customer;
use App\Models\MarketingEmailTemplate;
use App\Models\MarketingJourney;
use App\Models\MarketingMessage;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Sepet Kurtarma otomasyonu — çok kanallı, kupon eskalasyonlu.
 *
 * Terk = ödenmemiş sipariş (status=0 AND payment_status=0). Her terk için bir
 * marketing_journeys satırı açılır (enroll). Adımlar entered_at'e göre zamanlanır:
 *   1) E-posta hatırlatma (kuponsuz) — +1 saat
 *   2) SMS + tek kullanımlık indirim kuponu — +24 saat
 *   3) WhatsApp + aynı kupon — +48 saat (varsayılan kapalı; Meta bağlanınca açılır)
 *
 * KRİTİK DOĞRULUK: her gönderim ÖNCESİ "müşteri ödeme yaptı mı?" kontrol edilir
 * (aynı sipariş VEYA aynı müşterinin/e-postanın entered_at sonrası ödenmiş başka
 * siparişi). Ödeme varsa journey durur + ciro/kupon attribute edilir; ALMIŞ bir
 * müşteriye ASLA kupon/mesaj gitmez.
 *
 * GÜVENLİK: gerçek gönderim yalnızca settings['cart_recovery_enabled']===true VE
 * $live=true ise. Aksi halde simülasyon (journey ilerlemez, mesaj gitmez).
 */
class CartRecoveryAutomation
{
    public const KEY = 'cart_recovery';

    /** Terk penceresi: bu kadar saatten eski siparişler kaydedilmez (backlog sınırı). */
    public const ENROLL_WINDOW_HOURS = 72;

    public function __construct(
        protected UnifiedSender $sender,
        protected CartRecoveryCoupon $coupon,
    ) {}

    /** @return array<string,mixed> */
    protected function settings(int $tenantId): array
    {
        return Tenant::withoutGlobalScopes()->find($tenantId)?->settings ?? [];
    }

    public function isEnabled(int $tenantId): bool
    {
        return ! empty($this->settings($tenantId)['cart_recovery_enabled']);
    }

    public function couponAmount(int $tenantId): float
    {
        return (float) ($this->settings($tenantId)['cart_recovery_coupon_amount'] ?? 100);
    }

    /** Kupon min. sepet tutarı (0 = limit yok). */
    public function couponMinBasket(int $tenantId): float
    {
        return (float) ($this->settings($tenantId)['cart_recovery_coupon_min_basket'] ?? 0);
    }

    /**
     * Journey'e özel kupon koşulları [tutar, min sepet].
     *
     * mode=percent (varsayılan): tutar = sepetin %X'i, yuvarlama adımının (25₺)
     * katına yuvarlanır (550₺ sepet → %10=55 → 50₺; 1.200₺ → 120 → 125₺).
     * Min sepet = müşterinin kendi sepet tutarı, 100'e AŞAĞI yuvarlanır
     * (550→500, 1.200→1.200) — böylece kupon müşterinin mevcut sepetinde kesin geçerli.
     * mode=fixed: eski davranış (sabit tutar + sabit min sepet ayarı).
     *
     * @return array{0: float, 1: float}
     */
    public function couponTerms(int $tenantId, MarketingJourney $journey, Order $order): array
    {
        $s = $this->settings($tenantId);
        $mode = (string) ($s['cart_recovery_coupon_mode'] ?? 'percent');

        if ($mode !== 'percent') {
            return [$this->couponAmount($tenantId), $this->couponMinBasket($tenantId)];
        }

        $cart = (float) ($journey->meta['total_amount'] ?? $order->total_amount ?? 0);
        $pct = max(1, min(50, (float) ($s['cart_recovery_coupon_percent'] ?? 10)));
        $round = max(5, (int) ($s['cart_recovery_coupon_round'] ?? 25));

        $amount = max($round, round($cart * $pct / 100 / $round) * $round);
        $minBasket = max(0.0, floor($cart / 100) * 100);

        return [(float) $amount, $minBasket];
    }

    /**
     * Adım tanımları (ayarlarla özelleştirilebilir). after_minutes = entered_at'ten gecikme.
     *
     * @return list<array{after_minutes:int,channel:string,template:?string,coupon:bool}>
     */
    public function steps(int $tenantId): array
    {
        $s = $this->settings($tenantId);
        $ch = $s['cart_recovery_channels'] ?? ['email' => true, 'sms' => true, 'whatsapp' => false];

        $defs = [
            ['after_minutes' => (int) ($s['cart_recovery_delay_email'] ?? 60),    'channel' => 'email',    'template' => 'cart_recovery_1', 'coupon' => false],
            ['after_minutes' => (int) ($s['cart_recovery_delay_sms'] ?? 1440),     'channel' => 'sms',      'template' => null,              'coupon' => true],
            ['after_minutes' => (int) ($s['cart_recovery_delay_whatsapp'] ?? 2880), 'channel' => 'whatsapp', 'template' => null,             'coupon' => true],
        ];

        // Yalnızca açık kanallar; sıra korunur (gecikme artan olmalı)
        return array_values(array_filter($defs, fn ($d) => ! empty($ch[$d['channel']])));
    }

    /**
     * Terk edilmiş sepetleri tara ve journey'e kaydet (gönderim YOK).
     *
     * @return array{scanned:int,enrolled:int,skipped:int}
     */
    public function enroll(int $tenantId, int $limit = 500): array
    {
        $steps = $this->steps($tenantId);
        if ($steps === []) {
            return ['scanned' => 0, 'enrolled' => 0, 'skipped' => 0];
        }
        $firstDelay = (int) $steps[0]['after_minutes'];
        $windowStart = now()->subHours(self::ENROLL_WINDOW_HOURS);

        $orders = Order::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 0)
            ->where('payment_status', 0)
            // isPaid ile tutarlılık: kısmi/tam ödeme görülmüş sipariş terk DEĞİLDİR
            ->where(fn ($w) => $w->whereNull('paid_amount')->orWhere('paid_amount', '<=', 0))
            ->whereNotNull('customer_email')
            ->where('order_date', '>=', $windowStart)
            ->whereNotExists(function ($q) use ($tenantId) {
                $q->selectRaw('1')
                    ->from('marketing_journeys as mj')
                    ->whereColumn('mj.order_id', 'orders.id')
                    ->where('mj.tenant_id', $tenantId)
                    ->where('mj.automation', self::KEY);
            })
            ->orderBy('order_date')
            ->limit($limit)
            ->get();

        $enrolled = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            $email = trim((string) $order->customer_email);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            $enteredAt = $order->order_date ?? $order->created_at ?? now();
            $nextAt = Carbon::parse($enteredAt)->addMinutes($firstDelay);

            // Telefon: üye kaydından, yoksa misafir siparişinin adresinden
            // (SMS/WhatsApp erişimi + telefon-bazlı "zaten aldı" güvenlik eşleşmesi için)
            $phone = trim((string) ($order->customer?->phone
                ?? ($order->billing_address['phone'] ?? ($order->shipping_address['phone'] ?? ''))));

            // MÜKERRER KİŞİ KORUMASI: aynı kişinin hâlâ aktif bir journey'si varsa
            // veya son 7 günde bir journey'sinden mesaj gönderildiyse yeni journey AÇMA
            // (aynı kişiye paralel akış / ikinci kupon gitmesin).
            if ($this->personHasRecentJourney($tenantId, $order->customer_id, $email, $phone)) {
                $skipped++;
                continue;
            }

            MarketingJourney::create([
                'tenant_id' => $tenantId,
                'automation' => self::KEY,
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'recipient_email' => $email,
                'recipient_phone' => $phone !== '' ? $phone : null,
                'status' => 'active',
                'current_step' => 0,
                'entered_at' => $enteredAt,
                'next_action_at' => $nextAt,
                'meta' => [
                    'order_no' => $order->order_no,
                    'total_amount' => (float) $order->total_amount,
                    'enrolled_via' => 'scan',
                ],
            ]);
            $enrolled++;
        }

        return ['scanned' => $orders->count(), 'enrolled' => $enrolled, 'skipped' => $skipped];
    }

    /**
     * Gönderim anında GÜNCEL telefonu çöz: müşteri kartı (canlı) → sipariş fatura →
     * sipariş teslimat → journey kopyası. Değiştiyse journey'ye geri yazılır (panel
     * gösterimi + güvenlik eşleşmeleri güncel kalsın).
     */
    protected function freshPhone(MarketingJourney $journey, ?Order $order): ?string
    {
        $candidates = [
            $order?->customer_id ? trim((string) Customer::withoutGlobalScopes()->find($order->customer_id)?->phone) : '',
            trim((string) ($order->billing_address['phone'] ?? '')),
            trim((string) ($order->shipping_address['phone'] ?? '')),
            trim((string) $journey->recipient_phone),
        ];
        foreach ($candidates as $phone) {
            if ($phone !== '') {
                if ($phone !== $journey->recipient_phone) {
                    $journey->update(['recipient_phone' => $phone]);
                }

                return $phone;
            }
        }

        return null;
    }

    /**
     * Aynı kişinin (üye id / e-posta / telefon son-10) aktif VEYA son 7 günde mesaj
     * göndermiş bir cart_recovery journey'si var mı? (enroll mükerrer koruması)
     */
    protected function personHasRecentJourney(int $tenantId, ?int $customerId, string $email, string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone);
        $p10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        return MarketingJourney::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('automation', self::KEY)
            ->where(function ($w) {
                $w->where('status', 'active')
                    ->orWhere('last_sent_at', '>=', now()->subDays(7));
            })
            ->where(function ($w) use ($customerId, $email, $p10) {
                $any = false;
                if ($customerId) {
                    $w->orWhere('customer_id', $customerId);
                    $any = true;
                }
                if ($email !== '') {
                    $w->orWhereRaw('lower(recipient_email) = ?', [mb_strtolower($email)]);
                    $any = true;
                }
                if (strlen($p10) === 10) {
                    $w->orWhereRaw("right(regexp_replace(coalesce(recipient_phone, ''), '\\D', '', 'g'), 10) = ?", [$p10]);
                    $any = true;
                }
                if (! $any) {
                    $w->whereRaw('1 = 0'); // kimlik yok → koruma uygulanamaz, engelleme
                }
            })
            ->exists();
    }

    /**
     * Bu journey dışında, aynı kişiye ait ve son 7 günde mesaj GÖNDERMİŞ başka bir
     * journey var mı? (process mükerrer koruması — kopya journey'yi durdurmak için)
     */
    protected function otherJourneySentRecently(MarketingJourney $journey): bool
    {
        $digits = preg_replace('/\D/', '', (string) $journey->recipient_phone);
        $p10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;
        $email = (string) $journey->recipient_email;

        return MarketingJourney::withoutGlobalScopes()
            ->where('tenant_id', $journey->tenant_id)
            ->where('automation', self::KEY)
            ->where('id', '!=', $journey->id)
            ->where('last_sent_at', '>=', now()->subDays(7))
            ->where(function ($w) use ($journey, $email, $p10) {
                $any = false;
                if ($journey->customer_id) {
                    $w->orWhere('customer_id', $journey->customer_id);
                    $any = true;
                }
                if ($email !== '') {
                    $w->orWhereRaw('lower(recipient_email) = ?', [mb_strtolower($email)]);
                    $any = true;
                }
                if (strlen($p10) === 10) {
                    $w->orWhereRaw("right(regexp_replace(coalesce(recipient_phone, ''), '\\D', '', 'g'), 10) = ?", [$p10]);
                    $any = true;
                }
                if (! $any) {
                    $w->whereRaw('1 = 0');
                }
            })
            ->exists();
    }

    /**
     * Zamanı gelmiş journey'leri işle: dönüşüm kontrolü → (kupon) → gönderim → ilerlet.
     *
     * @return array{due:int,sent:int,converted:int,skipped:int,exhausted:int,simulated:bool}
     */
    public function process(int $tenantId, bool $live = false, int $limit = 200): array
    {
        $simulate = ! ($live && $this->isEnabled($tenantId));
        $steps = $this->steps($tenantId);

        // Warm-up: panelden ayarlanan çalışma-başı limit (kontrollü açılış; 0 = kapalı)
        $settingsLimit = (int) ($this->settings($tenantId)['cart_recovery_process_limit'] ?? 0);
        if ($settingsLimit > 0) {
            $limit = min($limit, $settingsLimit);
        }

        $due = MarketingJourney::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('automation', self::KEY)
            ->where('status', 'active')
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', now())
            ->orderBy('next_action_at')
            ->limit($limit)
            ->get();

        $sent = 0; $converted = 0; $skipped = 0; $exhausted = 0;

        foreach ($due as $journey) {
            $order = $journey->order_id ? Order::withoutGlobalScopes()->find($journey->order_id) : null;
            if (! $order) {
                $journey->update(['status' => 'stopped', 'stopped_reason' => 'expired', 'next_action_at' => null]);
                continue;
            }

            // KRİTİK: müşteri ödeme yaptı mı? (aynı sipariş veya aynı müşterinin yeni siparişi)
            if ($conv = $this->findConversion($order, $journey)) {
                $this->convertJourney($journey, $conv);
                $converted++;
                continue;
            }

            $stepIndex = $journey->current_step;
            if (! isset($steps[$stepIndex])) {
                $journey->update(['status' => 'exhausted', 'next_action_at' => null]);
                $exhausted++;
                continue;
            }

            $step = $steps[$stepIndex];
            $channel = $step['channel'];
            // Telefonu GÖNDERİM ANINDA güncel müşteri kartından çöz: journey'deki numara
            // enroll anının kopyası — CRM senkronu numarayı güncellediyse (Murat O.Y. vakası:
            // 2023'teki eski numarasına WA gitti) bayat kopya yerine güncel olan kullanılır.
            $to = $channel === 'email' ? $journey->recipient_email : $this->freshPhone($journey, $order);

            if ($simulate) { $skipped++; continue; }

            // MÜKERRER JOURNEY KORUMASI: bu journey henüz HİÇBİR mesaj göndermediyse ve
            // aynı kişinin (üye/e-posta/telefon) BAŞKA bir journey'sinden son 7 günde mesaj
            // gönderildiyse → bu kopyayı durdur. Frekans limiti sadece ERTELER; kopya
            // journey ertesi gün aynı kişiye İKİNCİ kuponu atardı (Ticimax aynı sepeti
            // dakikalar arayla iki kayıt olarak düşürebiliyor — Murat O.Y. vakası).
            if ($journey->last_sent_at === null && $this->otherJourneySentRecently($journey)) {
                $journey->update(['status' => 'stopped', 'stopped_reason' => 'duplicate_person', 'next_action_at' => null]);
                $skipped++;
                continue;
            }

            // Bu kanal için alıcı yok (ör. telefon yok) → adımı atla, sıradakine geç
            if (! $to) {
                $this->advance($journey, $stepIndex, $steps, null, 'no_recipient');
                if (! isset($steps[$stepIndex + 1])) { $exhausted++; }
                $skipped++;
                continue;
            }

            // Gönderim saat aralığı dışındaysa: adımı KAYBETME, açılışa ertele
            // (nextOpen TR saatiyle döner; DB UTC — çevirmeden yazılırsa 3 saat geç kalır)
            if ($next = SendWindow::nextOpen($this->settings($tenantId))) {
                $journey->update(['next_action_at' => $next->utc()]);
                $skipped++;
                continue;
            }

            // Frekans limiti aşıldıysa: kaybetme, cap resetlenince (yarın pencere açılışı) gönder
            if (FrequencyCap::exceeded($tenantId, $this->settings($tenantId), $journey->customer_id, (string) $to)) {
                $from = now()->addDay();
                $journey->update(['next_action_at' => (SendWindow::nextOpen($this->settings($tenantId), $from) ?? $from)->utc()]);
                $skipped++;
                continue;
            }

            // Kupon gereken adım → önce kupon üret (başarısızsa ertele, ilerletme).
            // Tutar/min-sepet müşterinin SEPETİNE göre kişisel hesaplanır (couponTerms).
            if ($step['coupon']) {
                // Kuponun süresi dolduysa/1 saatten az kaldıysa adımı ATLAMA (kullanıcı
                // kararı 2026-07-10): bu bizim gecikmemizdir (erteleme birikmesi) — YENİ
                // kupon üret, mesaj yeni kodla gitsin. Eski kod geçmişe alınır (dönüşüm
                // eşleşmesi için), Ticimax'ta kendi süresiyle ölür. buildMessage yine de
                // asla gerçek kalandan uzun süre vaat etmez (floor + 3 saat tavan).
                $expiresAt = $journey->meta['coupon_expires_at'] ?? null;
                $forceNew = (bool) ($expiresAt && Carbon::parse($expiresAt)->lessThanOrEqualTo(now()->addHour()));

                [$couponAmt, $couponMin] = $this->couponTerms($tenantId, $journey, $order);
                $code = $this->coupon->ensureFor($journey, $couponAmt,
                    (int) ($this->settings($tenantId)['cart_recovery_coupon_valid_hours'] ?? 48),
                    $couponMin, $forceNew);
                if (! $code) {
                    $journey->update(['next_action_at' => now()->addMinutes(30)]); // 30 dk sonra tekrar dene
                    $skipped++;
                    continue;
                }
                $journey->refresh();
            }

            $token = $channel === 'email' ? \Illuminate\Support\Str::random(40) : null;
            $message = $this->buildMessage($tenantId, $channel, $step, $journey, $order, $token);
            $log = $this->sender->send($tenantId, $channel, (string) $to, $message, [
                'source' => self::KEY,
                'source_type' => 'automation',
                'customer_id' => $journey->customer_id,
                'tracking_token' => $token,
                // Panel gözlemi + kanal atıfı için mesaj-journey bağı
                'meta' => [
                    'journey_id' => $journey->id,
                    'step' => $stepIndex + 1,
                    'order_no' => $journey->meta['order_no'] ?? $order->order_no,
                    'coupon_code' => $journey->meta['coupon_code'] ?? null,
                    'cart_total' => $journey->meta['total_amount'] ?? null,
                    'customer_name' => trim((string) ($order->customer_name ?? '')),
                ],
            ]);

            // WhatsApp şablonu Meta onayı beklerken (132001: template not found) veya
            // şablon-parametre uyumsuzluğunda (132000: param sayısı/sırası — kod düzeltilince
            // otomatik akar) adımı KAYBETME → birkaç saatte bir yeniden dene.
            if ($channel === 'whatsapp' && $log->status === 'failed'
                && (str_contains((string) $log->error, '132001')
                    || str_contains((string) $log->error, '132000'))) {
                $journey->update(['next_action_at' => now()->addHours(4)]);
                $skipped++;
                continue;
            }

            $this->advance($journey, $stepIndex, $steps, $log, null);

            if ($log->status === 'sent') { $sent++; } else { $skipped++; }
            if (! isset($steps[$stepIndex + 1])) { $exhausted++; }
        }

        return compact('sent', 'converted', 'skipped', 'exhausted')
            + ['due' => $due->count(), 'simulated' => $simulate];
    }

    /** Journey'i bir sonraki adıma ilerlet (veya tükenmişse kapat). */
    protected function advance(MarketingJourney $journey, int $stepIndex, array $steps, $log, ?string $note): void
    {
        $nextIndex = $stepIndex + 1;
        $nextAt = isset($steps[$nextIndex])
            ? Carbon::parse($journey->entered_at)->addMinutes((int) $steps[$nextIndex]['after_minutes'])
            : null;

        $meta = $journey->meta ?? [];
        if ($log) { $meta['last_message_id'] = $log->id; $meta['last_status'] = $log->status; }
        if ($log && $log->status === 'sent') {
            // Kanal atıfı için: GÖNDERİLMİŞ mesajların sıralı listesi (sonuncusu = atıf hedefi)
            $meta['sent_message_ids'] = array_merge($meta['sent_message_ids'] ?? [], [$log->id]);
        }
        if ($note) { $meta['last_note'] = $note; }

        $journey->update([
            'current_step' => $nextIndex,
            // last_sent_at = GERÇEKTEN gönderilmiş demektir; skipped/failed adım bu
            // damgayı almaz (mükerrer-kişi koruması ve 7g enroll kilidi buna dayanır)
            'last_sent_at' => ($log && $log->status === 'sent') ? now() : $journey->last_sent_at,
            'next_action_at' => $nextAt,
            'status' => $nextAt ? 'active' : 'exhausted',
            'meta' => $meta,
        ]);
    }

    /**
     * Adımları bitmiş (exhausted) journey'lerde dönüşüm süpürmesi.
     *
     * process() yalnız 'active' journey'leri tarar; son adım (WA) gönderilince journey
     * 'exhausted' olur ve bir daha taranmıyordu → son mesajdan SONRA gelen satışlar hiç
     * yakalanmıyordu (WA kanalı yapısal olarak hep 0 dönüşüm gösteriyordu). Bu süpürme,
     * son gönderimden sonraki 7 gün boyunca dönüşümü kontrol eder — sınırsız değil,
     * aylar sonraki organik alım otomasyona yazılmasın. Mesaj GÖNDERMEZ; bu yüzden
     * canlı/simülasyon ayrımı yoktur. Maliyet: findConversion ağır olduğundan her
     * journey en çok 4 saatte bir kontrol edilir (meta.last_sweep_at).
     */
    public function sweepExhausted(int $tenantId, int $limit = 40): array
    {
        $journeys = MarketingJourney::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('automation', self::KEY)
            ->where('status', 'exhausted')
            ->whereNotNull('last_sent_at')
            ->where('last_sent_at', '>=', now()->subDays(7))
            // ISO8601 (offset'li) bind: PG oturum TZ'si Europe/Istanbul — çıplak
            // 'Y-m-d H:i:s' string'i TR olarak yorumlanır ve eşik 3 saat kayardı
            ->whereRaw("coalesce((meta->>'last_sweep_at')::timestamptz, 'epoch') <= ?", [now()->subHours(4)->toIso8601String()])
            ->orderByRaw("coalesce((meta->>'last_sweep_at')::timestamptz, 'epoch')")
            ->limit($limit)
            ->get();

        $converted = 0;
        foreach ($journeys as $journey) {
            $order = $journey->order_id ? Order::withoutGlobalScopes()->find($journey->order_id) : null;
            if ($order && ($conv = $this->findConversion($order, $journey))) {
                $this->convertJourney($journey, $conv);
                $converted++;
                continue;
            }
            $journey->update(['meta' => array_merge($journey->meta ?? [], ['last_sweep_at' => now()->toIso8601String()])]);
        }

        return ['scanned' => $journeys->count(), 'converted' => $converted];
    }

    /**
     * Journey'yi dönüşmüş olarak kapatır: ciroyu doğru kanala atfeder + durumu yazar.
     * Hem process() (aktif akış) hem sweepExhausted() (adımları bitmiş akış) kullanır.
     */
    protected function convertJourney(MarketingJourney $journey, array $conv): ?string
    {
        // KANAL ATIFI: ciro, journey'de EN SON gönderilmiş mesajın kanalına yazılır.
        // Hiç mesaj gönderilmediyse organik dönüşümdür — kanala atıf YAPILMAZ.
        $attributedTo = $this->attributeToLastMessage($journey, $conv);

        $journey->update([
            'status' => 'converted',
            'stopped_reason' => $conv['reason'],
            'converted_at' => now(),
            'revenue' => $conv['revenue'],
            'next_action_at' => null,
            'meta' => array_merge($journey->meta ?? [], [
                'converted_order_id' => $conv['order_id'],
                'coupon_used' => $conv['coupon_used'],
                'attributed_channel' => $attributedTo,
            ]),
        ]);

        return $attributedTo;
    }

    /**
     * Dönüşüm cirosunu, journey'de EN SON gönderilmiş mesaja (kanalına) yazar.
     *
     * Kural (kullanıcı kararı 2026-07-14): mail gitti-almadı → SMS gitti-almadı →
     * WA gitti-ALDI ise ciro WA'ya yazılır. En son ne gittiyse ciro ONUN, ve YALNIZ
     * onun: dönüşüm başına TEK mesaj işaretlenir (converted_at doluysa üzerine
     * yazılmaz) → aynı ciro iki kanala asla sayılmaz.
     *
     * NOT: "alımdan önce gönderilmiş son mesaj" gibi bir zaman filtresi bilinçli olarak
     * YOK — alım anı için elimizdeki tek veri orders.order_date ve o siparişin OLUŞMA
     * anı (havalede ödeme sonra düşer), gerçek alım anı değil. Belirsiz bir damgaya
     * dayanıp ciroyu son mesajdan geri almak, kazandırdığından çok hata üretir.
     * Zaten satın alan müşteriye sonraki adım gitmez: process() her adımdan önce
     * findConversion çalıştırır (yalnız ~5 dk'lık Ticimax senkron gecikmesinde mesaj
     * yine de çıkabilir — o durumda da ciro en son gidene yazılır).
     *
     * Mesajsız dönüşüm organiktir → atıf yapılmaz (null).
     */
    protected function attributeToLastMessage(MarketingJourney $journey, array $conv): ?string
    {
        $sentIds = $journey->meta['sent_message_ids'] ?? [];
        if ($sentIds === []) {
            // Eski kayıtlar için geriye uyum: last_message_id gönderilmişse onu kullan
            $lastId = $journey->meta['last_message_id'] ?? null;
            if (! $lastId) {
                return null; // hiç mesaj yok → organik
            }
            $sentIds = [$lastId];
        }

        $msg = MarketingMessage::withoutGlobalScopes()
            ->where('tenant_id', $journey->tenant_id)
            ->where('source', self::KEY)
            ->where('status', 'sent')
            ->whereIn('id', $sentIds)
            ->orderByDesc('sent_at')
            ->orderByDesc('id') // aynı saniyede iki gönderim → sıra deterministik kalsın
            ->first();

        if (! $msg) {
            return null;
        }
        if (! $msg->converted_at) {
            $msg->update(['revenue' => $conv['revenue'], 'converted_at' => now()]);
        }

        return $msg->channel;
    }

    /**
     * Telefonun son 10 hanesi (5XXXXXXXXX) — formattan bağımsız karşılaştırma anahtarı.
     * "+90 543 528 56 00", "05435285600", "905435285600" → hepsi "5435285600".
     */
    protected function phone10(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) < 10) {
            return null;
        }
        $last10 = substr($digits, -10);

        return str_starts_with($last10, '5') ? $last10 : null;
    }

    /**
     * Sipariş artık "terk" değil mi? (ödendi / durumu ilerledi / iptal vb.)
     * status>0 = Ticimax durumu beklemeden çıktı (ödendi/onaylı/iptal — hepsi "dur" demek,
     * çünkü ilerlemiş bir siparişe hatırlatma YANLIŞ olur), payment_status=1 veya paid_amount>0
     * = ödeme görüldü. Hepsinde GÜVENLİ davranış: gönderme.
     */
    protected function isPaid(Order $order): bool
    {
        return (int) $order->status > 0
            || (int) $order->payment_status === 1
            || (float) $order->paid_amount > 0;
    }

    /**
     * Dönüşüm tespiti — gönderim öncesi ZORUNLU güvenlik kontrolü.
     * ALMIŞ MÜŞTERİYE ASLA MESAJ GİTMEZ prensibiyle GENİŞ eşleştirme yapılır:
     *   1) Terk siparişinin kendisi ödendiyse,
     *   2) Aynı müşteri (customer_id) entered_at sonrası ödeme yaptıysa,
     *   3) Aynı E-POSTA (büyük/küçük harf duyarsız) ödeme yaptıysa,
     *   4) Aynı TELEFON ödeme yaptıysa — misafir siparişleri dahil (fatura/teslimat
     *      adresindeki telefon + üye telefonu; son 10 hane normalize karşılaştırma).
     *      Ödenmiş siparişlerin ~%37'si misafir (customer_id yok) → SMS/WhatsApp
     *      telefona gittiği için telefon eşleşmesi kritik.
     *   5) Sepetten SONRA verilmemiş ama sonradan ÖDENMİŞ sipariş (ör. havale onayı):
     *      updated_at >= entered_at olan yakın tarihli siparişler de taranır.
     * Şüphede güvenli davranış: eşleşme varsa GÖNDERME. Kuponumuzu kullandıysa coupon_used=true.
     *
     * @return array{revenue:float,reason:string,order_id:int,coupon_used:bool}|null
     */
    protected function findConversion(Order $order, MarketingJourney $journey): ?array
    {
        if ($this->isPaid($order)) {
            return [
                'revenue' => (float) ($order->paid_amount ?: $order->total_amount),
                'reason' => 'paid',
                'order_id' => $order->id,
                'coupon_used' => false,
            ];
        }

        $email = mb_strtolower(trim((string) $journey->recipient_email));
        $phone10 = $this->phone10($journey->recipient_phone);

        // Kimlik bilgisi yoksa eşleştirme yapılamaz (teorik durum; enroll e-posta zorunlu)
        if (! $journey->customer_id && $email === '' && $phone10 === null) {
            return null;
        }

        $q = Order::withoutGlobalScopes()
            ->where('tenant_id', $journey->tenant_id)
            ->where('id', '!=', $order->id)
            ->where(function ($w) use ($journey) {
                // Terk sonrası verilen sipariş VEYA terkten önce açılıp SONRA ödenen
                // yakın tarihli sipariş (havale/EFT onayı gibi geç ödeme senaryosu)
                $w->where('order_date', '>=', $journey->entered_at)
                    ->orWhere(function ($w2) use ($journey) {
                        $w2->where('updated_at', '>=', $journey->entered_at)
                            ->where('order_date', '>=', Carbon::parse($journey->entered_at)->subDays(7));
                    });
            })
            ->where(function ($w) {
                $w->where('status', '>', 0)->orWhere('payment_status', 1)->orWhere('paid_amount', '>', 0);
            })
            ->where(function ($w) use ($journey, $email, $phone10) {
                if ($journey->customer_id) {
                    $w->orWhere('customer_id', $journey->customer_id);
                }
                if ($email !== '') {
                    $w->orWhereRaw('LOWER(customer_email) = ?', [$email]);
                }
                if ($phone10 !== null) {
                    // Misafir siparişleri: adres JSON'undaki telefon
                    $w->orWhereRaw("RIGHT(regexp_replace(coalesce(billing_address->>'phone',''), '\\D', '', 'g'), 10) = ?", [$phone10]);
                    $w->orWhereRaw("RIGHT(regexp_replace(coalesce(shipping_address->>'phone',''), '\\D', '', 'g'), 10) = ?", [$phone10]);
                    // Üye siparişleri: müşteri kaydındaki telefon
                    $w->orWhereExists(function ($sq) use ($phone10) {
                        $sq->selectRaw('1')->from('customers as cc')
                            ->whereColumn('cc.id', 'orders.customer_id')
                            ->whereRaw("RIGHT(regexp_replace(coalesce(cc.phone,''), '\\D', '', 'g'), 10) = ?", [$phone10]);
                    });
                }
            })
            ->orderByDesc('order_date')
            ->first();

        if (! $q) {
            return null;
        }

        // Güncel kod + yenileme geçmişindeki eski kodlar (müşteri SMS'teki ilk kodu kullanabilir)
        $ourCoupons = array_filter(array_merge(
            [(string) ($journey->meta['coupon_code'] ?? '')],
            (array) ($journey->meta['coupon_code_history'] ?? [])
        ));
        $usedCoupon = $ourCoupons !== []
            && in_array(mb_strtolower((string) $q->coupon_code), array_map('mb_strtolower', $ourCoupons), true);

        return [
            'revenue' => (float) ($q->paid_amount ?: $q->total_amount),
            'reason' => $usedCoupon ? 'paid_with_coupon' : 'paid_other',
            'order_id' => $q->id,
            'coupon_used' => $usedCoupon,
        ];
    }

    /**
     * Kanala göre mesaj içeriği üretir.
     * email → düzenlenebilir HTML şablon; sms/whatsapp → kısa metin (+kupon).
     *
     * @return array<string,mixed>
     */
    public function buildMessage(int $tenantId, string $channel, array $step, MarketingJourney $journey, Order $order, ?string $token = null): array
    {
        $vars = $this->messageVars($tenantId, $journey, $order);

        if ($channel === 'email') {
            $template = $step['template']
                ? MarketingEmailTemplate::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)->where('key', $step['template'])->where('is_active', true)->first()
                : null;

            $html = $template
                ? $template->render($vars)['html']
                : null;
            $subject = $template && ($s = $template->render($vars)['subject']) !== ''
                ? $s : 'Sepetinde ürünler seni bekliyor 🛒';

            return [
                'subject' => $subject,
                'html' => $html ? $this->injectTracking($html, $token) : null,
                'body' => $this->plainFallback($vars),
                'unsubscribe_url' => $vars['unsubscribe_url'],
            ];
        }

        $s = $this->settings($tenantId);

        // WhatsApp — proaktif gönderim Meta onaylı TEMPLATE ile (serbest metin 24s penceresi
        // dışında teslim edilmez). Param sırası onaylı 'sepet_kurtarma_kupon' şablonuyla birebir
        // (probe ile teslim edilen metinden doğrulandı, 2026-07-10): "Merhaba {{1}} 👋 /
        // {{2}} sepetindeki ürünler seni bekliyor! Sana özel {{3}} değerinde indirim kuponu
        // hazırladık / Kupon kodu: {{4}} / Yalnızca {{5}} geçerli" → isim İKİ kez.
        if ($channel === 'whatsapp' && $vars['coupon_code'] !== '') {
            // Kalan süre kuponun GERÇEK bitişinden hesaplanır (aksiyona çağıran aciliyet).
            $expiresAt = $journey->meta['coupon_expires_at'] ?? null;
            // DİKKAT yön: Carbon 3'te diff İŞARETLİ — expires->diffInMinutes(now()) gelecek
            // tarih için NEGATİF döner ve max(1,...) hep "1 saat"e sabitlerdi (2026-07-10 bug'ı)
            // FLOOR: söylenen süre HER ZAMAN gerçek kalandan küçük/eşit (90 dk kalan koda
            // "2 saat" deme — "1 saat" de; kodun fazlası olması sorun değil, eksiği olmaz)
            $remaining = $expiresAt
                ? max(1, (int) floor(now()->diffInMinutes(Carbon::parse($expiresAt)) / 60))
                : (int) ($s['cart_recovery_coupon_valid_hours'] ?? 48);

            // ACİLİYET SINIRI (kullanıcı kararı 2026-07-10): mesajda uzun süre ("44 saat")
            // yerine kısa pencere göster (varsayılan 3 saat) — ama ASLA kuponun gerçek
            // kalanından fazlası söylenmez (kod, söylenen süreden uzun geçerli = sorun yok;
            // tersi asla olmaz). Süresi 1 saatten az kalan koda process() zaten mesaj atmaz.
            $remaining = min($remaining, max(1, (int) ($s['cart_recovery_wa_urgency_hours'] ?? 3)));

            return [
                'template' => [
                    'name' => (string) ($s['cart_recovery_whatsapp_template_name'] ?? 'sepet_kurtarma_kupon'),
                    'lang' => 'tr',
                    // {{2}} onaylı şablonda "{{2}} sepetindeki ürünler seni bekliyor!" öznesi.
                    // İsim yerine sepet tutarı geçirilir → "1.559 TL'lik sepetindeki ürünler
                    // seni bekliyor!" (kullanıcı isteği; şablon değişmediği için Meta onayı
                    // gerekmez — isim zaten {{1}}'deki selamlamada var).
                    'params' => [
                        $vars['customer_name'],
                        $vars['cart_total_short'] . "'lik",
                        $vars['coupon_amount'] . '₺',
                        $vars['coupon_code'],
                        $remaining . ' saat',
                    ],
                ],
                // Log/önizleme için düz metin karşılığı
                'body' => "{$vars['customer_name']}, sepetindeki ürünler seni bekliyor! Sana özel {$vars['coupon_amount']}₺ indirim kuponu: {$vars['coupon_code']} (son {$remaining} saat). {$vars['cart_url']}",
            ];
        }

        // SMS / WhatsApp(kuponsuz fallback) — panelden düzenlenebilir metin şablonu (yoksa varsayılan)
        $tplKey = $channel === 'whatsapp' ? 'cart_recovery_whatsapp_template' : 'cart_recovery_sms_template';
        $tpl = trim((string) ($s[$tplKey] ?? ''));

        if ($tpl !== '') {
            $text = MarketingEmailTemplate::interpolate($tpl, $vars);
        } else {
            $minNote = (float) ($vars['coupon_min_basket'] ?? 0) > 0
                ? " ({$vars['coupon_min_basket']} TL uzeri gecerli)"
                : '';
            // Sepet tutarı metinde (kullanıcı isteği); ASCII + kısa biçim = 1 kontör (≤150 kr) hedefi
            $text = $vars['coupon_code'] !== ''
                ? "{$vars['shop_name']}: {$vars['cart_total_short']}'lik sepetin seni bekliyor! {$vars['coupon_amount']} TL indirim kodu: {$vars['coupon_code']}{$minNote}. Tamamla: {$vars['cart_url']}"
                : "{$vars['shop_name']}: {$vars['cart_total_short']}'lik sepetin seni bekliyor. Tamamla: {$vars['cart_url']}";
        }

        return [
            'body' => $text,
            'header' => $s['sms_header'] ?? null,
        ];
    }

    /**
     * Mesaj değişkenleri.
     *
     * @return array<string,string>
     */
    protected function messageVars(int $tenantId, MarketingJourney $journey, Order $order): array
    {
        $s = $this->settings($tenantId);
        $name = trim((string) ($order->customer_name ?: ($order->customer?->name ?? '')));
        $shopUrl = (string) ($s['shop_url'] ?? '#');
        $couponAmt = (float) ($journey->meta['coupon_amount'] ?? $this->couponAmount($tenantId));

        return [
            'customer_name' => $name !== '' ? $name : 'Değerli müşterimiz',
            'shop_name' => (string) (Tenant::withoutGlobalScopes()->find($tenantId)?->name ?? ''),
            'shop_url' => $shopUrl,
            'cart_total' => number_format((float) $order->total_amount, 2, ',', '.') . ' TL',
            // SMS/WA için kuruşsuz kısa biçim (kontör + okunabilirlik)
            'cart_total_short' => number_format(round((float) $order->total_amount), 0, ',', '.') . ' TL',
            'cart_url' => (string) ($s['cart_url'] ?? $shopUrl),
            'coupon_code' => (string) ($journey->meta['coupon_code'] ?? ''),
            'coupon_amount' => rtrim(rtrim(number_format($couponAmt, 2, ',', '.'), '0'), ','),
            'coupon_min_basket' => rtrim(rtrim(number_format((float) ($journey->meta['coupon_min_basket'] ?? 0), 2, ',', '.'), '0'), ','),
            'order_no' => (string) $order->order_no,
            'unsubscribe_url' => UnsubscribeLink::for($tenantId, (string) $journey->recipient_email),
        ];
    }

    protected function plainFallback(array $vars): string
    {
        return "Merhaba {$vars['customer_name']},\n\n"
            . "Sepetindeki ürünler seni bekliyor. Sepet tutarı: {$vars['cart_total']}.\n\n"
            . "Siparişini tamamlamak için: {$vars['cart_url']}";
    }

    /**
     * E-posta HTML'ine açılma pikseli + tıklama-takip link sarması ekler.
     * Abonelikten-çıkış linki (/u?) sarılmaz.
     */
    protected function injectTracking(string $html, ?string $token): string
    {
        if (! $token) {
            return $html;
        }
        $base = rtrim((string) config('app.url'), '/');

        // http(s) linklerini tıklama yönlendiricisinden geçir (hedef URL şifreli → açık-redirect yok)
        $html = preg_replace_callback('/href="(https?:\/\/[^"]+)"/i', function ($m) use ($base, $token) {
            $url = $m[1];
            if (str_contains($url, '/u?') || str_contains($url, '/e/c/')) {
                return $m[0]; // unsubscribe / zaten sarılmış
            }
            $enc = urlencode(\Illuminate\Support\Facades\Crypt::encryptString($url));

            return 'href="' . $base . '/e/c/' . $token . '?u=' . $enc . '"';
        }, $html);

        // Açılma pikseli
        $pixel = '<img src="' . $base . '/e/o/' . $token . '.gif" width="1" height="1" alt="" style="display:none;border:0">';
        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $pixel . '</body>', $html, 1);
        }

        return $html . $pixel;
    }
}
