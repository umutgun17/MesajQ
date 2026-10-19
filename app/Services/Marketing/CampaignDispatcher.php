<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignRecipient;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Toplu WhatsApp kampanya göndericisi.
 *
 * Kurallar:
 *  - 24s tekil-alıcı kapasitesi (WaCapacity) HİÇBİR koşulda aşılmaz; kapasite
 *    biterse kampanya durmaz, sıradaki turlarda/günlerde kaldığı yerden sürer.
 *  - Isıtma: tur başına gönderim = toplam_alıcı / (pencere_saati × 12 tur/saat),
 *    ayrıca PER_RUN_MAX tavanı. 5 dk'lık cron ile pencereye yayılır.
 *  - Gönderim saatleri tenant'ın pazarlama penceresine (SendWindow) tabidir.
 *  - UnifiedSender skip_gates=true ile çağrılır ÇÜNKÜ FrequencyCap işlemsel
 *    (sipariş bildirimi) mesajları da sayıyor; onun yerine kampanyaya özel
 *    cooldown uygulanır (son N günde WA pazarlama mesajı alan atlanır).
 *    Suppression (şikayet listesi) UnifiedSender içinde HER durumda çalışır.
 *  - Meta 131048/131049 (hız/pazarlama limiti) görülürse kampanya OTOMATİK
 *    duraklatılır — kanal kalitesini korumak öncelik.
 *  - Numara kalitesi (PhoneQualityGuard): Meta quality_rating RED olduğunda
 *    tüm gönderen kampanyalar otomatik duraklar, RED'den çıkınca bekçinin
 *    durdurdukları otomatik devam eder.
 */
class CampaignDispatcher
{
    /** Tek cron turunda kampanya başına mutlak tavan. */
    public const PER_RUN_MAX = 150;

    public function __construct(protected UnifiedSender $sender)
    {
    }

    public function run(int $tenantId, bool $live = false): array
    {
        $tenant = Tenant::find($tenantId);
        $s = $tenant?->settings ?? [];

        // Atıf her turda, kanaldan ve pencereden bağımsız çalışır
        $attributed = $this->attribute($tenantId, $live);

        $wa = $this->runWhatsapp($tenantId, $s, $live);
        $em = $this->runEmail($tenantId, $s, $live);

        return [
            'tenant' => $tenantId,
            'sent' => $wa['sent'],
            'skipped' => $wa['skipped'],
            'failed' => $wa['failed'],
            'email_sent' => $em['sent'],
            'email_skipped' => $em['skipped'],
            'email_failed' => $em['failed'],
            'attributed' => $attributed,
            'note' => implode(' ', array_filter([
                $wa['note'],
                $em['note'] ? 'email:'.$em['note'] : null,
            ])) ?: null,
        ];
    }

    /** @return array{sent:int, skipped:int, failed:int, note:?string} */
    protected function runWhatsapp(int $tenantId, array $s, bool $live): array
    {
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'note' => null];

        // Numara kalite bekçisi: Meta quality_rating RED ise gönderen kampanyalar
        // duraklatılır; RED'den çıkınca bekçinin durdurdukları otomatik devam eder.
        // Yalnızca ilgili kampanya varsa Meta'ya sorulur (10 dk cache).
        $guardRelevant = MarketingCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('channel', 'whatsapp')
            ->where(fn ($q) => $q->where('status', 'sending')
                ->orWhere(fn ($q2) => $q2->where('status', 'paused')->where('paused_reason', PhoneQualityGuard::PAUSE_REASON)))
            ->exists();
        if ($guardRelevant) {
            // Dry-run'da durum değiştirilmez, yalnız kalite okunur.
            if ($live) {
                $rating = PhoneQualityGuard::enforce($tenantId)['rating'] ?? null;
            } else {
                $rating = strtoupper((string) (PhoneQualityGuard::info($tenantId)['quality_rating'] ?? '')) ?: null;
            }
            if ($rating === 'RED') {
                $out['note'] = 'quality_red_auto_paused';

                return $out;
            }
        }

        $campaigns = MarketingCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('channel', 'whatsapp')
            ->where('status', 'sending')
            ->orderBy('started_at')
            ->get();

        if ($campaigns->isEmpty()) {
            $out['note'] = 'no_active_campaign';

            return $out;
        }

        if (empty($s['whatsapp_enabled'])) {
            $out['note'] = 'whatsapp_disabled';

            return $out;
        }

        if (! SendWindow::isOpen($s)) {
            $out['note'] = 'quiet_hours';

            return $out;
        }

        $capacity = WaCapacity::remainingForCampaigns($tenantId, $s);
        if ($capacity <= 0) {
            $out['note'] = 'daily_capacity_exhausted';
            Log::info("[campaign] t{$tenantId} 24s kapasite doldu, tur atlandı");

            return $out;
        }

        foreach ($campaigns as $campaign) {
            if ($capacity <= 0) {
                break;
            }
            $res = $this->processCampaign($campaign, $s, $capacity, $live);
            $out['sent'] += $res['sent'];
            $out['skipped'] += $res['skipped'];
            $out['failed'] += $res['failed'];
            $capacity -= $res['sent'];
        }

        return $out;
    }

    // ---------------------------------------------------------------- e-posta

    /** Tek cron turunda e-posta kampanyası başına mutlak tavan. */
    public const EMAIL_PER_RUN_MAX = 250;

    /** Günlük e-posta kampanya limiti (tenant settings['email_daily_limit']). */
    public static function emailDailyLimit(array $s): int
    {
        return max(0, (int) ($s['email_daily_limit'] ?? 1000));
    }

    /** Bugün (TR) kampanyalardan gönderilen e-posta sayısı. */
    public static function emailSentToday(int $tenantId): int
    {
        return (int) DB::table('marketing_messages')
            ->where('tenant_id', $tenantId)
            ->where('channel', 'email')
            ->where('source_type', 'campaign')
            ->where('status', 'sent')
            ->where('sent_at', '>=', now('Europe/Istanbul')->startOfDay()->utc())
            ->count();
    }

    /** @return array{sent:int, skipped:int, failed:int, note:?string} */
    protected function runEmail(int $tenantId, array $s, bool $live): array
    {
        $out = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'note' => null];

        $campaigns = MarketingCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('channel', 'email')
            ->where('status', 'sending')
            ->orderBy('started_at')
            ->get();

        if ($campaigns->isEmpty()) {
            return $out;
        }

        if (! $this->sender->driver('email')?->isAvailable($tenantId)) {
            $out['note'] = 'channel_disabled';

            return $out;
        }

        if (! SendWindow::isOpen($s)) {
            $out['note'] = 'quiet_hours';

            return $out;
        }

        $daily = self::emailDailyLimit($s);
        $remaining = $daily - self::emailSentToday($tenantId);
        if ($remaining <= 0) {
            $out['note'] = 'daily_limit_exhausted';

            return $out;
        }

        foreach ($campaigns as $campaign) {
            if ($remaining <= 0) {
                break;
            }
            $res = $this->processEmailCampaign($campaign, $daily, $remaining, $live);
            $out['sent'] += $res['sent'];
            $out['skipped'] += $res['skipped'];
            $out['failed'] += $res['failed'];
            $remaining -= $res['sent'];
        }

        return $out;
    }

    protected function processEmailCampaign(MarketingCampaign $campaign, int $daily, int $remaining, bool $live): array
    {
        $res = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        // Damla damla: günlük limit pencere saatine yayılır (5 dk'lık cron = saatte 12 tur)
        $windowHours = max(1, (int) $campaign->send_window_hours);
        $pace = max(1, (int) ceil($daily / ($windowHours * 12)));
        $batch = min($pace, self::EMAIL_PER_RUN_MAX, $remaining);

        $pending = MarketingCampaignRecipient::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit($batch)
            ->get();

        if ($pending->isEmpty()) {
            $this->maybeComplete($campaign);

            return $res;
        }

        if (trim((string) $campaign->subject) === '' || trim((string) $campaign->body_html) === '') {
            $campaign->update([
                'status' => 'paused',
                'paused_reason' => 'Konu veya içerik boş — düzenleyip devam ettirin',
            ]);

            return $res;
        }

        if (! $live) {
            Log::info("[campaign] DRY c{$campaign->id} (email): {$pending->count()} alıcı gönderilecekti (batch={$batch})");

            return $res;
        }

        $consecutiveFails = 0;
        foreach ($pending as $recipient) {
            $email = mb_strtolower(trim((string) $recipient->email));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipient->update(['status' => 'skipped', 'skip_reason' => 'invalid_email']);
                $res['skipped']++;

                continue;
            }

            // Abonelikten çıkanlar/bounce (skip_gates=true olduğundan burada bakılır)
            if (\App\Models\EmailSuppression::isSuppressed($campaign->tenant_id, $email)) {
                $recipient->update(['status' => 'skipped', 'skip_reason' => 'unsubscribed']);
                $res['skipped']++;

                continue;
            }

            if ($this->recentlyEmailed($campaign, $email)) {
                $recipient->update(['status' => 'skipped', 'skip_reason' => 'recent_contact']);
                $res['skipped']++;

                continue;
            }

            $token = Str::random(40);
            $rendered = EmailCampaignRenderer::render($campaign, $recipient->name, $email, $token);

            $msg = $this->sender->send($campaign->tenant_id, 'email', $email, [
                'subject' => $rendered['subject'],
                'html' => $rendered['html'],
                'body' => $rendered['body'],
                'unsubscribe_url' => $rendered['unsubscribe_url'],
            ], [
                'source' => 'campaign:'.$campaign->id,
                'source_type' => 'campaign',
                'customer_id' => $recipient->customer_id,
                'tracking_token' => $token,
                'skip_gates' => true, // suppression yukarıda + pencere tur başında; FrequencyCap işlemseli de sayar
                'meta' => array_filter([
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'coupon_code' => $campaign->coupon_code,
                    'customer_name' => $recipient->name,
                ]),
            ]);

            if ($msg->status === 'sent') {
                $recipient->update([
                    'status' => 'sent',
                    'message_id' => $msg->id,
                    'tracking_token' => $token,
                    'sent_at' => now(),
                ]);
                $res['sent']++;
                $consecutiveFails = 0;
            } elseif ($msg->status === 'skipped') {
                $recipient->update(['status' => 'skipped', 'skip_reason' => $msg->skip_reason]);
                $res['skipped']++;
            } else {
                $recipient->update([
                    'status' => 'failed',
                    'message_id' => $msg->id,
                    'error' => Str::limit((string) $msg->error, 480),
                ]);
                $res['failed']++;
                // MTA sistemik hata veriyorsa (kuyruk dolu, sendmail yok...) kampanyayı koru
                if (++$consecutiveFails >= 5) {
                    $campaign->update([
                        'status' => 'paused',
                        'paused_reason' => 'Art arda e-posta gönderim hatası — sunucu/MTA kontrol edilmeli',
                    ]);
                    Log::warning("[campaign] c{$campaign->id} (email) art arda {$consecutiveFails} hata — otomatik duraklatıldı");

                    return $res;
                }
            }

            usleep(mt_rand(50_000, 150_000));
        }

        $this->maybeComplete($campaign);

        return $res;
    }

    /** Son N günde bu adrese kampanya/otomasyon e-postası gitti mi? */
    protected function recentlyEmailed(MarketingCampaign $campaign, string $email): bool
    {
        $days = max(0, (int) $campaign->cooldown_days);
        if ($days === 0) {
            return false;
        }

        return DB::table('marketing_messages')
            ->where('tenant_id', $campaign->tenant_id)
            ->where('channel', 'email')
            ->where('status', 'sent')
            ->whereIn('source_type', ['automation', 'campaign'])
            ->where('sent_at', '>=', now()->subDays($days))
            ->whereRaw('lower(recipient) = ?', [$email])
            ->exists();
    }

    protected function processCampaign(MarketingCampaign $campaign, array $s, int $capacity, bool $live): array
    {
        $res = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        // Damla damla gönderim: GÜNLÜK kampanya bütçesi (limit - rezerv) pencere
        // saatine yayılır — liste 20k olsa da gün içi akış sabit ve doğal kalır.
        // Limit panelden artınca ertesi günün temposu kendiliğinden yükselir.
        $windowHours = max(1, (int) $campaign->send_window_hours);
        $dailyBudget = max(1, WaCapacity::limit($s) - WaCapacity::reserve($s));
        $pace = (int) ceil($dailyBudget / ($windowHours * 12));
        $pace = max(1, (int) round($pace * (mt_rand(80, 120) / 100))); // ±%20 doğal dalgalanma
        $batch = min($pace, self::PER_RUN_MAX, $capacity);

        $pending = MarketingCampaignRecipient::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit($batch)
            ->get();

        if ($pending->isEmpty()) {
            $this->maybeComplete($campaign);

            return $res;
        }

        // Güvenlik freni: boş sabit/kuponsuz-kupon değişkeni tüm partiyi Meta
        // hatasına sürükler → gönderime hiç girmeden kampanyayı duraklat.
        foreach ($campaign->template_params ?? [] as $i => $p) {
            $src = $p['source'] ?? 'static';
            $bad = ($src === 'static' && trim((string) ($p['value'] ?? '')) === '')
                || ($src === 'coupon' && ! trim((string) $campaign->coupon_code));
            if ($bad) {
                $campaign->update([
                    'status' => 'paused',
                    'paused_reason' => 'Şablon değişkeni {{'.($i + 1).'}} boş — düzenleyip devam ettirin',
                ]);
                Log::warning("[campaign] c{$campaign->id} boş şablon değişkeni ({{".($i + 1).'}}) — otomatik duraklatıldı');

                return $res;
            }
        }

        if (! $live) {
            Log::info("[campaign] DRY c{$campaign->id}: {$pending->count()} alıcı gönderilecekti (batch={$batch})");

            return $res;
        }

        // Panelde (Mesajlar/analitik) gerçek metin görünsün diye şablon gövdesi
        // bir kez çekilir, alıcı başına {{n}} değerleriyle doldurulur.
        $bodyTemplate = $this->templateBodyText($campaign);

        $limitErrors = 0;
        foreach ($pending as $recipient) {
            $phone = WaPhone::normalize($recipient->phone);
            if (! $phone) {
                $recipient->update(['status' => 'skipped', 'skip_reason' => 'invalid_phone']);
                $res['skipped']++;

                continue;
            }

            if ($this->recentlyContacted($campaign, $phone)) {
                $recipient->update(['status' => 'skipped', 'skip_reason' => 'recent_contact']);
                $res['skipped']++;

                continue;
            }

            $token = Str::random(40);
            $urlButton = $campaign->url_button ?? [];
            $useButton = ! empty($urlButton['enabled']);
            $targetUrl = $urlButton['target_url'] ?? null;

            $params = $this->buildParams($campaign, $recipient);
            $message = [
                'body' => $this->renderBody($bodyTemplate, $params),
                'template' => array_filter([
                    'name' => $campaign->template_name,
                    'lang' => $campaign->template_lang ?: 'tr',
                    'params' => $params,
                    'url_button_param' => $useButton ? $token : null,
                ], fn ($v) => $v !== null),
            ];

            $msg = $this->sender->send($campaign->tenant_id, 'whatsapp', $phone, $message, [
                'source' => 'campaign:'.$campaign->id,
                'source_type' => 'campaign',
                'customer_id' => $recipient->customer_id,
                'tracking_token' => $token,
                'skip_gates' => true, // gerekçe: sınıf yorumu — kendi cooldown'umuz + SendWindow tur başında
                'meta' => array_filter([
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'coupon_code' => $campaign->coupon_code,
                    'customer_name' => $recipient->name,
                    'click_url' => $targetUrl,
                ]),
            ]);

            if ($msg->status === 'sent') {
                $recipient->update([
                    'status' => 'sent',
                    'message_id' => $msg->id,
                    'tracking_token' => $token,
                    'sent_at' => now(),
                ]);
                $res['sent']++;
            } elseif ($msg->status === 'skipped') {
                $recipient->update(['status' => 'skipped', 'skip_reason' => $msg->skip_reason]);
                $res['skipped']++;
            } else {
                $recipient->update([
                    'status' => 'failed',
                    'message_id' => $msg->id,
                    'error' => Str::limit((string) $msg->error, 480),
                ]);
                $res['failed']++;
                if (preg_match('/13104[89]|130429/', (string) $msg->error)) {
                    $limitErrors++;
                }
            }

            // Meta hız/pazarlama limiti sinyali → kampanyayı koru, otomatik durdur
            if ($limitErrors >= 3 || preg_match('/131049/', (string) ($msg->error ?? ''))) {
                $campaign->update([
                    'status' => 'paused',
                    'paused_reason' => 'Meta hız/pazarlama limiti sinyali — otomatik duraklatıldı',
                ]);
                Log::warning("[campaign] c{$campaign->id} Meta limit sinyaliyle otomatik duraklatıldı");

                return $res;
            }

            usleep(mt_rand(400_000, 1_500_000)); // mesajlar arası 0,4–1,5 sn rastgele aralık (bulk imzası bırakma)
        }

        $this->maybeComplete($campaign);

        return $res;
    }

    protected function maybeComplete(MarketingCampaign $campaign): void
    {
        $left = MarketingCampaignRecipient::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->exists();
        if (! $left && $campaign->status === 'sending') {
            $campaign->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }

    /** Son N günde bu numaraya WA pazarlama mesajı (otomasyon/kampanya) gitti mi? */
    protected function recentlyContacted(MarketingCampaign $campaign, string $phone): bool
    {
        $days = max(0, (int) $campaign->cooldown_days);
        if ($days === 0) {
            return false;
        }
        $p10 = WaPhone::last10($phone);

        return DB::table('marketing_messages')
            ->where('tenant_id', $campaign->tenant_id)
            ->where('channel', 'whatsapp')
            ->where('status', 'sent')
            ->whereIn('source_type', ['automation', 'campaign'])
            ->where('sent_at', '>=', now()->subDays($days))
            ->whereRaw("right(regexp_replace(recipient, '\\D', '', 'g'), 10) = ?", [$p10])
            ->exists();
    }

    /** Şablonun BODY metnini Meta'dan (5 dk cache) çeker; bulunamazsa null. */
    protected function templateBodyText(MarketingCampaign $campaign): ?string
    {
        $key = "wa_tpl_body_{$campaign->tenant_id}_{$campaign->template_name}_{$campaign->template_lang}";

        return \Illuminate\Support\Facades\Cache::remember($key, 300, function () use ($campaign) {
            $channel = \App\Models\Channel::withoutGlobalScopes()
                ->where('tenant_id', $campaign->tenant_id)
                ->where('type', 'like', '%whatsapp%')
                ->where('is_active', true)
                ->first();
            if (! $channel) {
                return null;
            }
            $svc = app(\App\Services\WhatsAppService::class);
            $res = $svc->listTemplates(
                $channel->settings['business_account_id'] ?? '',
                $svc->getAccessToken($channel->credentials ?? []) ?? ''
            );
            foreach ($res['data'] ?? [] as $tpl) {
                if (($tpl['name'] ?? '') === $campaign->template_name
                    && ($tpl['language'] ?? '') === ($campaign->template_lang ?: 'tr')) {
                    foreach ($tpl['components'] ?? [] as $comp) {
                        if (($comp['type'] ?? '') === 'BODY') {
                            return $comp['text'] ?? null;
                        }
                    }
                }
            }

            return null;
        }) ?: null;
    }

    /** {{n}} yer tutucularını gerçek değerlerle doldurur. */
    protected function renderBody(?string $template, array $params): ?string
    {
        if ($template === null) {
            return null;
        }
        foreach ($params as $i => $value) {
            $template = str_replace('{{'.($i + 1).'}}', $value, $template);
        }

        return $template;
    }

    /** {{1}}..{{n}} parametrelerini kampanya tanımından üretir. */
    protected function buildParams(MarketingCampaign $campaign, MarketingCampaignRecipient $recipient): array
    {
        $params = [];
        foreach (($campaign->template_params ?? []) as $spec) {
            $source = $spec['source'] ?? 'static';
            $params[] = match ($source) {
                'name' => trim((string) $recipient->name) !== '' ? trim($recipient->name) : 'Değerli müşterimiz',
                'coupon' => (string) ($campaign->coupon_code ?? ''),
                default => (string) ($spec['value'] ?? ''),
            };
        }

        return $params;
    }

    /**
     * Dönüşüm atfı: gönderilmiş, henüz dönüşmemiş alıcılar için atıf penceresi
     * içinde telefon (son-10) eşleşmeli ÖDENMİŞ sipariş aranır. Kupon kodu
     * kampanya koduysa coupon_used işaretlenir. Sadece kendi kanal siparişleri
     * (pazaryeri telefonları maskeli/yanıltıcı).
     */
    public function attribute(int $tenantId, bool $live): int
    {
        $tenant = Tenant::find($tenantId);
        $s = $tenant?->settings ?? [];
        $ownSources = $s['order_notify_sources'] ?? ['TicimaxWeb', 'NativeAndroid', 'NativeIOS'];

        $campaigns = MarketingCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['sending', 'paused', 'completed'])
            ->get();

        $attributed = 0;
        foreach ($campaigns as $campaign) {
            $window = max(1, (int) $campaign->attribution_days);

            $candidates = MarketingCampaignRecipient::withoutGlobalScopes()
                ->where('campaign_id', $campaign->id)
                ->where('status', 'sent')
                ->whereNull('converted_at')
                ->where('sent_at', '>=', now()->subDays($window))
                ->orderBy('sent_at')
                ->limit(400)
                ->get();

            foreach ($candidates as $recipient) {
                $p10 = WaPhone::last10($recipient->phone);
                $email = mb_strtolower(trim((string) $recipient->email));
                if (! $p10 && $email === '') {
                    continue;
                }

                $order = DB::table('orders')
                    ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
                    ->where('orders.tenant_id', $tenantId)
                    ->whereIn('orders.order_source', $ownSources)
                    ->where('orders.order_date', '>=', $recipient->sent_at->toIso8601String())
                    ->where(function ($q) {
                        $q->where('orders.status', '>', 0)
                            ->orWhere('orders.payment_status', 1)
                            ->orWhere('orders.paid_amount', '>', 0);
                    })
                    ->where(function ($q) use ($p10, $email, $recipient) {
                        if ($p10) {
                            $q->orWhereRaw("right(regexp_replace(coalesce(orders.billing_address->>'phone',''), '\\D', '', 'g'), 10) = ?", [$p10])
                                ->orWhereRaw("right(regexp_replace(coalesce(orders.shipping_address->>'phone',''), '\\D', '', 'g'), 10) = ?", [$p10])
                                ->orWhereRaw("right(regexp_replace(coalesce(customers.phone,''), '\\D', '', 'g'), 10) = ?", [$p10]);
                        }
                        if ($email !== '') {
                            $q->orWhereRaw('lower(orders.customer_email) = ?', [$email]);
                        }
                        if ($recipient->customer_id) {
                            $q->orWhere('orders.customer_id', $recipient->customer_id);
                        }
                    })
                    ->orderBy('orders.order_date')
                    ->select('orders.id', 'orders.paid_amount', 'orders.total_amount', 'orders.coupon_code', 'orders.order_date')
                    ->first();

                if (! $order) {
                    continue;
                }

                $revenue = (float) ($order->paid_amount > 0 ? $order->paid_amount : $order->total_amount);
                $couponUsed = $campaign->coupon_code
                    && $order->coupon_code
                    && strcasecmp(trim($order->coupon_code), trim($campaign->coupon_code)) === 0;

                if ($live) {
                    $recipient->update([
                        'converted_at' => now(),
                        'revenue' => $revenue,
                        'order_id' => $order->id,
                        'coupon_used' => $couponUsed,
                    ]);
                    if ($recipient->message_id) {
                        DB::table('marketing_messages')
                            ->where('id', $recipient->message_id)
                            ->whereNull('converted_at')
                            ->update(['converted_at' => now(), 'revenue' => $revenue, 'updated_at' => now()]);
                    }
                }
                $attributed++;
            }
        }

        return $attributed;
    }
}
