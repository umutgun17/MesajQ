<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\GoogleAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);

        if (! $tenant) {
            return response()->json(['message' => 'Tenant bulunamadi.'], 404);
        }

        $settings = $this->maskSettings($tenant->settings ?? []);

        return response()->json([
            'settings' => $settings,
            'tenant' => [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settings' => 'required|array',
        ]);

        $tenant = $this->resolveTenant($request);

        if (! $tenant) {
            return response()->json(['message' => 'Tenant bulunamadi.'], 404);
        }

        // Merge incoming settings with existing (don't overwrite unrelated keys)
        $existing = $tenant->settings ?? [];
        $incoming = $validated['settings'];

        // If sensitive keys are masked (unchanged), preserve originals
        $sensitiveKeys = ['openai_api_key', 'claude_api_key', 'meta_app_secret', 'meta_verify_token'];
        foreach ($sensitiveKeys as $sk) {
            if (isset($incoming[$sk]) && str_starts_with($incoming[$sk], '***')) {
                unset($incoming[$sk]);
            }
        }

        // Whitelist: sadece izin verilen ayar anahtarları değiştirilebilir
        $allowedKeys = [
            // Genel Ayarlar sayfası: dil burada kaydedilir — whitelist'te
            // olmayan anahtar array_intersect_key ile SESSİZCE düşer, o yüzden
            // yeni ayar eklerken buraya eklemeyi unutma!
            'default_language',
            'auto_reply', 'auto_reply_delay_min', 'auto_reply_delay_max', 'auto_reply_min_confidence',
            'ai_system_prompt', 'ai_model',
            // Claude bakiye kapısı: kiracının anahtarını hangi modüller harcayabilir
            // (chatbot her zaman serbest; bkz. App\Services\Ai\ClaudeKeyPolicy)
            'ai_inbox_enabled', 'ai_social_enabled', 'ai_calls_enabled',
            'openai_api_key', 'claude_api_key',
            'meta_app_id', 'meta_app_secret', 'meta_verify_token',
            'review_widget_enabled', 'review_widget_platform', 'review_widget_theme',
            'wheel_widget_enabled', 'wheel_widget_domains',
            'shipping_widget_enabled', 'shipping_widget_domains', 'shipping_cutoff_time',
            'shipping_days', 'shipping_urgent_minutes', 'shipping_widget_selector',
            'shipping_friday_extension', 'shipping_day_cutoffs',
            'announce_widget_enabled', 'announce_widget_domains',
            'stock_alert_widget_enabled', 'stock_alert_widget_domains', 'stock_alert_selector',
            'announce_bell_enabled', 'announce_bell_items', 'announce_bell_position',
            'announce_topbar_enabled', 'announce_topbar_text', 'announce_topbar_url', 'announce_topbar_link_text',
            'announce_topbar_ends_at', 'announce_topbar_bg', 'announce_topbar_fg', 'announce_topbar_dismissible',
            'announce_topbar_highlights',
            'announce_ticker_enabled', 'announce_ticker_items', 'announce_ticker_interval',
            'announce_ticker_bg', 'announce_ticker_fg', 'announce_ticker_dismissible',
            'announce_trust_enabled', 'announce_trust_items', 'announce_trust_selector',
            'chat_widget_enabled', 'chat_widget_domains', 'chat_bot_name', 'chat_greeting',
            'chat_quick_replies', 'chat_extra_instructions', 'chat_strict_rules', 'chat_order_notes',
            'chat_handoff_enabled', 'chat_theme_color', 'chat_widget_template',
            'chat_greeting_style', 'chat_teaser_text', 'chat_launcher_style',
            // Kademeli açılım + doğruluk kapıları + mesai saatleri (4 Eyl)
            'chat_rollout_mode', 'chat_rollout_limit',
            'chat_answer_mode', 'chat_min_confidence',
            'chat_allow_faq', 'chat_allow_order_status', 'chat_allow_products', 'chat_ask_detail',
            'chat_hours_enabled', 'chat_hours',
            'push_enabled', 'push_domains',
            'push_prompt_title', 'push_prompt_body', 'push_prompt_allow', 'push_prompt_deny',
            'push_prompt_color', 'push_prompt_position', 'push_prompt_delay', 'push_prompt_snooze_days',
            'rec_cart_enabled', 'rec_widget_domains', 'rec_cart_max_products', 'rec_cart_timer_seconds',
            'rec_cart_title', 'rec_cart_subtitle', 'rec_cart_badge', 'rec_cart_cta',
            'rec_cart_accent', 'rec_cart_highlight', 'rec_cart_atc_enabled',
            'rec_checkout_enabled', 'rec_checkout_max_products', 'rec_checkout_timer_seconds',
            'rec_checkout_title', 'rec_checkout_subtitle', 'rec_checkout_badge',
            'rec_checkout_cta', 'rec_checkout_continue',
            'rec_checkout_top_enabled', 'rec_checkout_top_count', 'rec_checkout_top_selector',
            'rec_checkout_bottom_enabled', 'rec_checkout_bottom_title', 'rec_checkout_bottom_selector',
            'rec_checkout_offer_mode', 'rec_checkout_offer_percent', 'rec_checkout_offer_max_amount',
            'rec_checkout_offer_valid_minutes', 'rec_checkout_offer_title', 'rec_checkout_offer_text',
            'rec_checkout_offer_cta', 'rec_checkout_offer_applied',
            'rec_pinned_products',
            'email_enabled', 'email_from_address', 'email_from_name', 'email_reply_to', 'email_unsubscribe_url',
            'ga4_property_id',
            'returns',
            'returns_integrations',
        ];
        $incoming = array_intersect_key($incoming, array_flip($allowedKeys));

        // Kargo sayacı ayarları — biçim doğrula, bozuksa kaydetme (widget varsayılana düşer)
        if (isset($incoming['shipping_cutoff_time']) && ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $incoming['shipping_cutoff_time'])) {
            unset($incoming['shipping_cutoff_time']);
        }
        if (isset($incoming['shipping_days'])) {
            $days = array_values(array_unique(array_filter(
                array_map('intval', explode(',', (string) $incoming['shipping_days'])),
                fn ($d) => $d >= 1 && $d <= 7
            )));
            sort($days);
            $incoming['shipping_days'] = implode(',', $days);
        }
        if (isset($incoming['shipping_urgent_minutes'])) {
            $incoming['shipping_urgent_minutes'] = (string) max(5, min(720, (int) $incoming['shipping_urgent_minutes']));
        }
        // Gün-bazlı kesim takvimi: JSON {"1":{"t":"HH:MM","prev":bool},…} — geçersiz
        // satırlar düşer, hiç geçerli satır kalmazsa boş kaydedilir (widget eski
        // days+cutoff ayarlarına geri döner). Boş string = takvimi bilerek kaldırma.
        if (isset($incoming['shipping_day_cutoffs'])) {
            $clean = [];
            $raw = json_decode((string) $incoming['shipping_day_cutoffs'], true);
            if (is_array($raw)) {
                foreach ($raw as $day => $row) {
                    $d = (int) $day;
                    $t = is_array($row) ? (string) ($row['t'] ?? '') : '';
                    if ($d >= 1 && $d <= 7 && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                        $clean[(string) $d] = ['t' => $t, 'prev' => (bool) ($row['prev'] ?? false)];
                    }
                }
            }
            $incoming['shipping_day_cutoffs'] = empty($clean) ? '' : json_encode($clean);
        }

        // AI Chatbot — kademeli açılım + doğruluk kapıları + mesai (4 Eyl).
        // Bozuk değer kaydedilmez: bunlar son tüketiciye görünen davranışı
        // belirliyor, sessizce varsayılana düşmek yerine temizlenip yazılır.
        if (isset($incoming['chat_rollout_limit'])) {
            $incoming['chat_rollout_limit'] = (string) max(0, min(1000000, (int) $incoming['chat_rollout_limit']));
        }
        if (isset($incoming['chat_rollout_mode']) && ! in_array($incoming['chat_rollout_mode'], ['off', 'limited', 'all'], true)) {
            unset($incoming['chat_rollout_mode']);
        }
        if (isset($incoming['chat_answer_mode']) && ! in_array($incoming['chat_answer_mode'], ['strict', 'open'], true)) {
            unset($incoming['chat_answer_mode']);
        }
        if (isset($incoming['chat_min_confidence'])) {
            $incoming['chat_min_confidence'] = (string) max(0, min(1, (float) $incoming['chat_min_confidence']));
        }
        if (isset($incoming['chat_hours'])) {
            $incoming['chat_hours'] = json_encode(
                \App\Services\Chat\ChatBusinessHours::sanitize($incoming['chat_hours'])
            );
        }

        // Ürün Öneri (sepete ekleme + ödeme adımı) — sayısal/renk alanlarını sanitize et
        if (isset($incoming['rec_cart_max_products'])) {
            $incoming['rec_cart_max_products'] = (string) max(1, min(8, (int) $incoming['rec_cart_max_products']));
        }
        if (isset($incoming['rec_checkout_max_products'])) {
            $incoming['rec_checkout_max_products'] = (string) max(1, min(10, (int) $incoming['rec_checkout_max_products']));
        }
        if (isset($incoming['rec_checkout_top_count'])) {
            $incoming['rec_checkout_top_count'] = (string) max(1, min(6, (int) $incoming['rec_checkout_top_count']));
        }
        foreach (['rec_checkout_top_selector', 'rec_checkout_bottom_selector'] as $sk) {
            if (isset($incoming[$sk])) {
                $incoming[$sk] = mb_substr(trim(strip_tags((string) $incoming[$sk])), 0, 200);
            }
        }
        // Sabitlenen ürünler: JSON [{id, mode:first|include, anchor:?int, surfaces:[atc|checkout]}], en çok 20
        if (isset($incoming['rec_pinned_products'])) {
            $raw = $incoming['rec_pinned_products'];
            $arr = is_string($raw) ? json_decode($raw, true) : $raw;
            $clean = [];
            foreach (is_array($arr) ? $arr : [] as $pin) {
                if (! is_array($pin) || (int) ($pin['id'] ?? 0) <= 0) {
                    continue;
                }
                $surf = array_values(array_intersect(['atc', 'checkout'], (array) ($pin['surfaces'] ?? ['atc', 'checkout'])));
                $clean[] = [
                    'id' => (int) $pin['id'],
                    'name' => mb_substr(strip_tags((string) ($pin['name'] ?? '')), 0, 160),
                    'image' => mb_substr((string) ($pin['image'] ?? ''), 0, 500),
                    'mode' => ($pin['mode'] ?? 'include') === 'first' ? 'first' : 'include',
                    'anchor' => (int) ($pin['anchor'] ?? 0) > 0 ? (int) $pin['anchor'] : null,
                    'anchor_name' => mb_substr(strip_tags((string) ($pin['anchor_name'] ?? '')), 0, 160),
                    'surfaces' => $surf ?: ['atc', 'checkout'],
                ];
                if (count($clean) >= 20) {
                    break;
                }
            }
            $incoming['rec_pinned_products'] = json_encode($clean, JSON_UNESCAPED_UNICODE);
            \Illuminate\Support\Facades\Cache::forget("rec:pins:{$tenant->id}");
        }
        if (isset($incoming['rec_checkout_offer_mode'])) {
            $incoming['rec_checkout_offer_mode'] = $incoming['rec_checkout_offer_mode'] === 'coupon' ? 'coupon' : 'campaign';
        }
        if (isset($incoming['rec_checkout_offer_percent'])) {
            $incoming['rec_checkout_offer_percent'] = (string) max(1, min(70, (float) $incoming['rec_checkout_offer_percent']));
        }
        if (isset($incoming['rec_checkout_offer_max_amount'])) {
            $incoming['rec_checkout_offer_max_amount'] = (string) max(0, (float) $incoming['rec_checkout_offer_max_amount']);
        }
        if (isset($incoming['rec_checkout_offer_valid_minutes'])) {
            $incoming['rec_checkout_offer_valid_minutes'] = (string) max(5, min(240, (int) $incoming['rec_checkout_offer_valid_minutes']));
        }
        foreach (['rec_checkout_offer_title', 'rec_checkout_offer_text', 'rec_checkout_offer_cta', 'rec_checkout_offer_applied'] as $tk) {
            if (isset($incoming[$tk])) {
                $incoming[$tk] = mb_substr(strip_tags((string) $incoming[$tk]), 0, 160);
            }
        }
        foreach (['rec_cart_timer_seconds', 'rec_checkout_timer_seconds'] as $sk) {
            if (isset($incoming[$sk])) {
                $incoming[$sk] = (string) max(0, min(3600, (int) $incoming[$sk]));
            }
        }
        foreach (['rec_cart_accent', 'rec_cart_highlight'] as $ck) {
            if (isset($incoming[$ck]) && $incoming[$ck] !== '' && ! preg_match('/^#[0-9a-fA-F]{6}$/', (string) $incoming[$ck])) {
                unset($incoming[$ck]);
            }
        }
        foreach (['rec_cart_title', 'rec_cart_subtitle', 'rec_cart_badge', 'rec_cart_cta',
            'rec_checkout_title', 'rec_checkout_subtitle', 'rec_checkout_badge',
            'rec_checkout_cta', 'rec_checkout_continue', 'rec_checkout_bottom_title'] as $tk) {
            if (isset($incoming[$tk])) {
                $incoming[$tk] = mb_substr(trim(strip_tags((string) $incoming[$tk])), 0, 120);
            }
        }

        // Kampanya Duyuruları — JSON listeleri ve tarih/renk alanlarını sanitize et
        $this->sanitizeAnnounce($incoming);

        // İade kuralları para-kritik — kaydetmeden önce tip/aralık sanitize et
        if (isset($incoming['returns'])) {
            unset($incoming['returns']); // iade modülü bu üründe yok
        }

        // İade entegrasyon ayarları (SMS gönderici başlığı, Sürat iade adresi, dry-run bayrakları)
        if (isset($incoming['returns_integrations']) && is_array($incoming['returns_integrations'])) {
            $incoming['returns_integrations'] = $this->sanitizeReturnsIntegrations(
                (array) ($existing['returns_integrations'] ?? []),
                $incoming['returns_integrations']
            );
        }

        $merged = array_merge($existing, $incoming);

        $tenant->update(['settings' => $merged]);

        // Kargo/duyuru widget'ları tenant'ı cache'ler — kaydet → anında etki
        \Illuminate\Support\Facades\Cache::forget("shipping:tenant:{$tenant->slug}");
        \Illuminate\Support\Facades\Cache::forget("announce:tenant:{$tenant->slug}");
        \Illuminate\Support\Facades\Cache::forget("rec:tenant:{$tenant->slug}");
        \App\Services\Chat\TenantChatSettings::forget($tenant->id);
        // Chatbot da kargo takvimini ve onaylı bilgi havuzunu cache'liyor: kargo
        // kesim saati panelden değişince bot AYNI ANDA yeni saati söylemeli
        // (vitrin rozetiyle çelişmemesi bu bağa dayanıyor).
        \App\Services\Chat\ChatShipping::forget($tenant->id);
        \App\Services\Chat\ChatKnowledge::forget($tenant->id);

        return response()->json([
            'message' => 'Ayarlar kaydedildi.',
            'settings' => $this->maskSettings($tenant->fresh()->settings),
        ]);
    }

    /**
     * Kampanya Duyuruları ayarlarını yerinde sanitize eder.
     * JSON listeleri (bell/trust) yeniden kurulur: yalnız bilinen alanlar, uzunluk clamp'li;
     * bozuk JSON kaydedilmez (mevcut değer korunur). Tarih "YYYY-MM-DDTHH:MM", renk "#rrggbb".
     */
    private function sanitizeAnnounce(array &$incoming): void
    {
        $str = fn ($v, int $max) => mb_substr(trim((string) $v), 0, $max);

        if (isset($incoming['announce_bell_items'])) {
            $items = json_decode((string) $incoming['announce_bell_items'], true);
            if (! is_array($items)) {
                unset($incoming['announce_bell_items']);
            } else {
                $clean = [];
                foreach (array_slice($items, 0, 20) as $it) {
                    if (! is_array($it)) {
                        continue;
                    }
                    $title = $str($it['title'] ?? '', 120);
                    if ($title === '') {
                        continue;
                    }
                    $url = $str($it['url'] ?? '', 500);
                    if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
                        $url = '';
                    }
                    $dt = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string) $v) ? (string) $v : '';
                    $clean[] = [
                        'id' => $str($it['id'] ?? '', 40) ?: (string) (count($clean) + 1),
                        'title' => $title,
                        'text' => $str($it['text'] ?? '', 500),
                        'url' => $url,
                        'starts_at' => $dt($it['starts_at'] ?? ''),
                        'ends_at' => $dt($it['ends_at'] ?? ''),
                    ];
                }
                $incoming['announce_bell_items'] = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        if (isset($incoming['announce_bell_position'])) {
            $incoming['announce_bell_position'] = $incoming['announce_bell_position'] === 'left' ? 'left' : 'right';
        }

        foreach (['announce_topbar_text' => 200, 'announce_topbar_link_text' => 40] as $key => $max) {
            if (isset($incoming[$key])) {
                $incoming[$key] = $str($incoming[$key], $max);
            }
        }
        if (isset($incoming['announce_topbar_url'])) {
            $url = $str($incoming['announce_topbar_url'], 500);
            $incoming['announce_topbar_url'] = ($url === '' || preg_match('#^https?://#i', $url)) ? $url : '';
        }
        if (isset($incoming['announce_topbar_ends_at'])
            && $incoming['announce_topbar_ends_at'] !== ''
            && ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string) $incoming['announce_topbar_ends_at'])) {
            unset($incoming['announce_topbar_ends_at']);
        }
        // Metin içi vurgular: en fazla 4 ifade, her biri renkli + opsiyonel link
        if (isset($incoming['announce_topbar_highlights'])) {
            $items = json_decode((string) $incoming['announce_topbar_highlights'], true);
            if (! is_array($items)) {
                unset($incoming['announce_topbar_highlights']);
            } else {
                $clean = [];
                foreach (array_slice($items, 0, 4) as $it) {
                    if (! is_array($it)) {
                        continue;
                    }
                    $text = $str($it['text'] ?? '', 60);
                    if ($text === '') {
                        continue;
                    }
                    $url = $str($it['url'] ?? '', 500);
                    if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
                        $url = '';
                    }
                    $color = (string) ($it['color'] ?? '');
                    $clean[] = [
                        'text' => $text,
                        'url' => $url,
                        'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#f97316',
                    ];
                }
                $incoming['announce_topbar_highlights'] = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        foreach (['announce_topbar_bg', 'announce_topbar_fg', 'announce_ticker_bg', 'announce_ticker_fg'] as $key) {
            if (isset($incoming[$key]) && $incoming[$key] !== '' && ! preg_match('/^#[0-9a-fA-F]{6}$/', (string) $incoming[$key])) {
                unset($incoming[$key]);
            }
        }

        if (isset($incoming['announce_ticker_interval'])) {
            $incoming['announce_ticker_interval'] = (string) max(2, min(15, (int) $incoming['announce_ticker_interval'] ?: 4));
        }
        // Kayan madde barı: en fazla 6 madde, ikon whitelist'i güven rozetleriyle ortak
        if (isset($incoming['announce_ticker_items'])) {
            $items = json_decode((string) $incoming['announce_ticker_items'], true);
            if (! is_array($items)) {
                unset($incoming['announce_ticker_items']);
            } else {
                $icons = ['truck', 'zap', 'refresh', 'shield', 'lock', 'phone', 'star', 'box', 'check', 'card'];
                $clean = [];
                foreach (array_slice($items, 0, 6) as $it) {
                    if (! is_array($it)) {
                        continue;
                    }
                    $text = $str($it['text'] ?? '', 120);
                    if ($text === '') {
                        continue;
                    }
                    $url = $str($it['url'] ?? '', 500);
                    if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
                        $url = '';
                    }
                    $clean[] = [
                        'icon' => in_array($it['icon'] ?? '', $icons, true) ? $it['icon'] : 'check',
                        'text' => $text,
                        'url' => $url,
                    ];
                }
                $incoming['announce_ticker_items'] = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        if (isset($incoming['announce_trust_items'])) {
            $items = json_decode((string) $incoming['announce_trust_items'], true);
            if (! is_array($items)) {
                unset($incoming['announce_trust_items']);
            } else {
                $icons = ['truck', 'zap', 'refresh', 'shield', 'lock', 'phone', 'star', 'box', 'check', 'card'];
                $clean = [];
                foreach (array_slice($items, 0, 6) as $it) {
                    if (! is_array($it)) {
                        continue;
                    }
                    $title = $str($it['title'] ?? '', 60);
                    if ($title === '') {
                        continue;
                    }
                    $clean[] = [
                        'icon' => in_array($it['icon'] ?? '', $icons, true) ? $it['icon'] : 'check',
                        'title' => $title,
                        'subtitle' => $str($it['subtitle'] ?? '', 80),
                    ];
                }
                $incoming['announce_trust_items'] = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        if (isset($incoming['announce_trust_selector'])) {
            $incoming['announce_trust_selector'] = $str($incoming['announce_trust_selector'], 200);
        }
    }

    /** Hassas alanları maskele (üst düzey API anahtarları + iç içe Sürat şifresi). */
    private function maskSettings(array $settings): array
    {
        return self::maskSettingsArray($settings);
    }

    /**
     * Hassas ayar anahtarlarını maskeler / kaldırır.
     * Paylaşımlı yardımcı: hem SettingsController hem AuthController (auth/me, login)
     * tenant.settings dönerken buradan geçirmeli — sırlar frontend'e sızmasın.
     */
    public static function maskSettingsArray(array $settings): array
    {
        $mask = function (?string $v): string {
            $v = (string) $v;

            return str_repeat('*', max(0, strlen($v) - 4)) . substr($v, -4);
        };

        foreach (['openai_api_key', 'claude_api_key', 'meta_app_secret', 'meta_verify_token'] as $sk) {
            if (! empty($settings[$sk])) {
                $settings[$sk] = $mask($settings[$sk]);
            }
        }

        // DKIM özel anahtarı asla frontend'e dönmez (komutla üretilir, salt-okunur)
        unset($settings['email_dkim_private']);

        // Sürat Kargo şifreleri (iç içe) — frontend'e açık dönmez
        if (! empty($settings['returns_integrations']['surat_kargo']['password'])) {
            $settings['returns_integrations']['surat_kargo']['password'] = $mask($settings['returns_integrations']['surat_kargo']['password']);
        }
        if (! empty($settings['returns_integrations']['surat_kargo']['web_password'])) {
            $settings['returns_integrations']['surat_kargo']['web_password'] = $mask($settings['returns_integrations']['surat_kargo']['web_password']);
        }

        // DHL Kargo (MNG) sırları — frontend'e açık dönmez
        foreach (['client_secret', 'password'] as $secret) {
            if (! empty($settings['returns_integrations']['dhl_kargo'][$secret])) {
                $settings['returns_integrations']['dhl_kargo'][$secret] = $mask($settings['returns_integrations']['dhl_kargo'][$secret]);
            }
        }

        // Karta iade (Faz 4 original) sağlayıcı sırları — frontend'e açık dönmez.
        foreach ([['iyzico', 'api_key'], ['iyzico', 'secret_key'], ['paytr', 'merchant_key'], ['paytr', 'merchant_salt']] as [$prov, $field]) {
            if (! empty($settings['returns_integrations']['refund']['original'][$prov][$field])) {
                $settings['returns_integrations']['refund']['original'][$prov][$field] =
                    $mask($settings['returns_integrations']['refund']['original'][$prov][$field]);
            }
        }

        return $settings;
    }

    /**
     * İade entegrasyon ayarlarını whitelist + tip güvenli birleştir.
     * Yalnızca bilinen anahtarlar; mevcut değerlerin üzerine gelenler yazılır.
     */
    private function sanitizeReturnsIntegrations(array $existing, array $incoming): array
    {
        $out = $existing;

        if (isset($incoming['sms']) && is_array($incoming['sms'])) {
            $sms = (array) ($out['sms'] ?? []);
            if (array_key_exists('enabled', $incoming['sms'])) {
                $sms['enabled'] = (bool) $incoming['sms']['enabled'];
            }
            if (array_key_exists('dry_run', $incoming['sms'])) {
                $sms['dry_run'] = (bool) $incoming['sms']['dry_run'];
            }
            if (array_key_exists('msgheader', $incoming['sms'])) {
                $sms['msgheader'] = $incoming['sms']['msgheader'] !== null
                    ? mb_substr(trim((string) $incoming['sms']['msgheader']), 0, 20)
                    : null;
            }
            $out['sms'] = $sms;
        }

        if (isset($incoming['surat_kargo']) && is_array($incoming['surat_kargo'])) {
            $sk = (array) ($out['surat_kargo'] ?? []);
            if (array_key_exists('enabled', $incoming['surat_kargo'])) {
                $sk['enabled'] = (bool) $incoming['surat_kargo']['enabled'];
            }
            if (array_key_exists('dry_run', $incoming['surat_kargo'])) {
                $sk['dry_run'] = (bool) $incoming['surat_kargo']['dry_run'];
            }
            if (array_key_exists('username', $incoming['surat_kargo'])) {
                $sk['username'] = mb_substr(trim((string) $incoming['surat_kargo']['username']), 0, 100);
            }
            // Şifre: maskeli ('*' içeren) geldiyse mevcut korunur; doluysa güncellenir.
            if (array_key_exists('password', $incoming['surat_kargo'])) {
                $pw = (string) $incoming['surat_kargo']['password'];
                if ($pw !== '' && ! str_contains($pw, '*')) {
                    $sk['password'] = $pw;
                }
            }
            // Takip (web servisi) şifresi — aynı maskeleme kuralı.
            if (array_key_exists('web_password', $incoming['surat_kargo'])) {
                $wpw = (string) $incoming['surat_kargo']['web_password'];
                if ($wpw !== '' && ! str_contains($wpw, '*')) {
                    $sk['web_password'] = $wpw;
                }
            }
            if (array_key_exists('kargo_turu', $incoming['surat_kargo'])) {
                $sk['kargo_turu'] = (int) $incoming['surat_kargo']['kargo_turu'];
            }
            if (array_key_exists('desi', $incoming['surat_kargo'])) {
                $sk['desi'] = (float) $incoming['surat_kargo']['desi'];
            }
            // İade kodu geçerlilik süresi (gün) — 1..60 clamp.
            if (array_key_exists('expire_days', $incoming['surat_kargo'])) {
                $sk['expire_days'] = max(1, min(60, (int) $incoming['surat_kargo']['expire_days']));
            }
            if (isset($incoming['surat_kargo']['merchant']) && is_array($incoming['surat_kargo']['merchant'])) {
                $m = (array) ($sk['merchant'] ?? []);
                foreach (['name', 'phone', 'email', 'address', 'city', 'district', 'code'] as $f) {
                    if (array_key_exists($f, $incoming['surat_kargo']['merchant'])) {
                        $m[$f] = mb_substr(trim((string) $incoming['surat_kargo']['merchant'][$f]), 0, 250);
                    }
                }
                $sk['merchant'] = $m;
            }
            $out['surat_kargo'] = $sk;
        }

        // Talep kodu önekleri (iade/iptal/kusur — örn. tba/tbp/tbk): önek + 8 rakam formatı.
        // Küçük harf saklanır; 'i'/'ı' YASAK (Sürat Türkçe katlamalı case-insensitive arıyor,
        // i→İ≠I çakışması canlıda doğrulandı 27.07). Boş = varsayılan format (IADE-XXXXXXXX).
        if (isset($incoming['code_prefixes']) && is_array($incoming['code_prefixes'])) {
            $cp = (array) ($out['code_prefixes'] ?? []);
            foreach (['return', 'cancel', 'defect', 'shortage', 'returned'] as $t) {
                if (array_key_exists($t, $incoming['code_prefixes'])) {
                    $v = mb_strtolower(trim((string) $incoming['code_prefixes'][$t]), 'UTF-8');
                    $v = preg_replace('/[^a-hj-z0-9]/', '', $v); // i hariç ascii harf + rakam
                    $v = mb_substr($v, 0, 6);
                    // 2 karakterden kısa önek anlamsız/riskli → boş say (varsayılana düşer).
                    $cp[$t] = strlen($v) >= 2 ? $v : '';
                }
            }
            $out['code_prefixes'] = $cp;
        }

        // EKSİK/HATALI ÜRÜN iadesi (personel): özür kuponu + Ticimax kalem/not ayarları (ShortageService).
        if (isset($incoming['shortage']) && is_array($incoming['shortage'])) {
            $sh = (array) ($out['shortage'] ?? []);
            $in = $incoming['shortage'];
            foreach (['coupon_enabled', 'coupon_free_shipping'] as $b) {
                if (array_key_exists($b, $in)) {
                    $sh[$b] = (bool) $in[$b];
                }
            }
            if (array_key_exists('coupon_amount', $in)) {
                $sh['coupon_amount'] = max(0, min(100000, round((float) $in['coupon_amount'], 2)));
            }
            if (array_key_exists('coupon_min_amount', $in)) {
                $sh['coupon_min_amount'] = max(0, min(1000000, round((float) $in['coupon_min_amount'], 2)));
            }
            if (array_key_exists('coupon_days', $in)) {
                $sh['coupon_days'] = max(1, min(730, (int) $in['coupon_days']));
            }
            if (array_key_exists('coupon_prefix', $in)) {
                $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $in['coupon_prefix']));
                $sh['coupon_prefix'] = mb_substr($v, 0, 10) ?: 'OZUR';
            }
            if (array_key_exists('coupon_grup_adi', $in)) {
                $sh['coupon_grup_adi'] = mb_substr(trim((string) $in['coupon_grup_adi']), 0, 60) ?: 'Özür Kuponu';
            }
            foreach (['item_durum_id', 'item_islem'] as $i) {
                if (array_key_exists($i, $in)) {
                    $sh[$i] = max(0, (int) $in[$i]);
                }
            }
            if (array_key_exists('note_field', $in)) {
                $sh['note_field'] = max(0, min(3, (int) $in['note_field']));
            }
            $out['shortage'] = $sh;
        }

        // Kargo sağlayıcı seçimi ('surat-kargo' | 'dhl-kargo') — CargoProviderFactory okur.
        if (array_key_exists('cargo_provider', $incoming)) {
            $cp = (string) $incoming['cargo_provider'];
            if (in_array($cp, ['surat-kargo', 'dhl-kargo'], true)) {
                $out['cargo_provider'] = $cp;
            }
        }

        if (isset($incoming['dhl_kargo']) && is_array($incoming['dhl_kargo'])) {
            $dk = (array) ($out['dhl_kargo'] ?? []);
            foreach (['enabled', 'dry_run', 'test_mode'] as $b) {
                if (array_key_exists($b, $incoming['dhl_kargo'])) {
                    $dk[$b] = (bool) $incoming['dhl_kargo'][$b];
                }
            }
            if (array_key_exists('client_id', $incoming['dhl_kargo'])) {
                $dk['client_id'] = mb_substr(trim((string) $incoming['dhl_kargo']['client_id']), 0, 100);
            }
            if (array_key_exists('customer_number', $incoming['dhl_kargo'])) {
                $dk['customer_number'] = mb_substr(trim((string) $incoming['dhl_kargo']['customer_number']), 0, 50);
            }
            // Sırlar: maskeli ('*' içeren) geldiyse mevcut korunur; doluysa güncellenir (Sürat kuralı).
            foreach (['client_secret', 'password'] as $secret) {
                if (array_key_exists($secret, $incoming['dhl_kargo'])) {
                    $val = (string) $incoming['dhl_kargo'][$secret];
                    if ($val !== '' && ! str_contains($val, '*')) {
                        $dk[$secret] = $val;
                    }
                }
            }
            if (array_key_exists('packaging_type', $incoming['dhl_kargo'])) {
                $dk['packaging_type'] = max(1, min(4, (int) $incoming['dhl_kargo']['packaging_type']));
            }
            foreach (['desi', 'kg'] as $f) {
                if (array_key_exists($f, $incoming['dhl_kargo'])) {
                    $dk[$f] = (float) $incoming['dhl_kargo'][$f];
                }
            }
            if (array_key_exists('expire_days', $incoming['dhl_kargo'])) {
                $dk['expire_days'] = max(1, min(60, (int) $incoming['dhl_kargo']['expire_days']));
            }
            $out['dhl_kargo'] = $dk;
        }

        if (isset($incoming['nebim']) && is_array($incoming['nebim'])) {
            $nb = (array) ($out['nebim'] ?? []);
            if (array_key_exists('enabled', $incoming['nebim'])) {
                $nb['enabled'] = (bool) $incoming['nebim']['enabled'];
            }
            if (array_key_exists('dry_run', $incoming['nebim'])) {
                $nb['dry_run'] = (bool) $incoming['nebim']['dry_run'];
            }
            // Opsiyonel altyapı override'ları (hassas değil).
            foreach (['base_host', 'database', 'proc_order_detail', 'cancel_reason_code'] as $f) {
                if (array_key_exists($f, $incoming['nebim'])) {
                    $nb[$f] = mb_substr(trim((string) $incoming['nebim'][$f]), 0, 100);
                }
            }
            foreach (['port_read', 'port_write'] as $f) {
                if (array_key_exists($f, $incoming['nebim'])) {
                    $nb[$f] = (int) $incoming['nebim'][$f];
                }
            }
            $out['nebim'] = $nb;
        }

        if (isset($incoming['ticimax']) && is_array($incoming['ticimax'])) {
            $tc = (array) ($out['ticimax'] ?? []);
            foreach (['enabled', 'dry_run', 'notify_customer'] as $f) {
                if (array_key_exists($f, $incoming['ticimax'])) {
                    $tc[$f] = (bool) $incoming['ticimax'][$f];
                }
            }
            if (array_key_exists('cancel_status', $incoming['ticimax'])) {
                $tc['cancel_status'] = mb_substr(trim((string) $incoming['ticimax']['cancel_status']), 0, 40);
            }
            $out['ticimax'] = $tc;
        }

        // Faz 4 — Para iadesi (store_credit kupon ayarları + dry-run/enabled).
        if (isset($incoming['refund']) && is_array($incoming['refund'])) {
            $rf = (array) ($out['refund'] ?? []);
            foreach (['enabled', 'dry_run'] as $f) {
                if (array_key_exists($f, $incoming['refund'])) {
                    $rf[$f] = (bool) $incoming['refund'][$f];
                }
            }
            if (isset($incoming['refund']['store_credit']) && is_array($incoming['refund']['store_credit'])) {
                $sc = (array) ($rf['store_credit'] ?? []);
                if (array_key_exists('valid_days', $incoming['refund']['store_credit'])) {
                    $sc['valid_days'] = max(1, min(3650, (int) $incoming['refund']['store_credit']['valid_days']));
                }
                if (array_key_exists('code_prefix', $incoming['refund']['store_credit'])) {
                    $sc['code_prefix'] = mb_substr(trim((string) $incoming['refund']['store_credit']['code_prefix']), 0, 20);
                }
                if (array_key_exists('grup_adi', $incoming['refund']['store_credit'])) {
                    $sc['grup_adi'] = mb_substr(trim((string) $incoming['refund']['store_credit']['grup_adi']), 0, 60);
                }
                $rf['store_credit'] = $sc;
            }
            // original = karta iade (iyzico/PayTR) kimlikleri + dry-run + OdemeTipi eşlemesi.
            if (isset($incoming['refund']['original']) && is_array($incoming['refund']['original'])) {
                $or = (array) ($rf['original'] ?? []);
                $inOr = $incoming['refund']['original'];

                foreach (['enabled', 'dry_run'] as $f) {
                    if (array_key_exists($f, $inOr)) {
                        $or[$f] = (bool) $inOr[$f];
                    }
                }
                // OdemeTipi → sağlayıcı eşlemesi: {"17":"paytr","14":"iyzico"} (yalnız iyzico/paytr kabul).
                if (array_key_exists('odeme_tipi_map', $inOr) && is_array($inOr['odeme_tipi_map'])) {
                    $map = [];
                    foreach ($inOr['odeme_tipi_map'] as $code => $prov) {
                        $code = (string) (int) $code;
                        $prov = strtolower(trim((string) $prov));
                        if ($code !== '0' && in_array($prov, ['iyzico', 'paytr'], true)) {
                            $map[$code] = $prov;
                        }
                    }
                    $or['odeme_tipi_map'] = $map;
                }
                // Maskeli ('*' içeren) gelen sırlar mevcut değeri KORUR; düz metin güncellenir.
                $secret = function (array $src, string $key, array &$dst) {
                    if (array_key_exists($key, $src)) {
                        $v = (string) $src[$key];
                        if ($v !== '' && ! str_contains($v, '*')) {
                            $dst[$key] = $v;
                        }
                    }
                };
                if (isset($inOr['iyzico']) && is_array($inOr['iyzico'])) {
                    $iy = (array) ($or['iyzico'] ?? []);
                    if (array_key_exists('base_url', $inOr['iyzico'])) {
                        $iy['base_url'] = mb_substr(trim((string) $inOr['iyzico']['base_url']), 0, 120);
                    }
                    $secret($inOr['iyzico'], 'api_key', $iy);
                    $secret($inOr['iyzico'], 'secret_key', $iy);
                    $or['iyzico'] = $iy;
                }
                if (isset($inOr['paytr']) && is_array($inOr['paytr'])) {
                    $pt = (array) ($or['paytr'] ?? []);
                    if (array_key_exists('merchant_id', $inOr['paytr'])) {
                        $pt['merchant_id'] = mb_substr(trim((string) $inOr['paytr']['merchant_id']), 0, 40);
                    }
                    $secret($inOr['paytr'], 'merchant_key', $pt);
                    $secret($inOr['paytr'], 'merchant_salt', $pt);
                    $or['paytr'] = $pt;
                }
                $rf['original'] = $or;
            }
            $out['refund'] = $rf;
        }

        return $out;
    }

    public function saveGa4(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);

        if (! $tenant) {
            return response()->json(['message' => 'Tenant bulunamadi.'], 404);
        }

        $request->validate([
            'ga4_property_id' => 'required|string|max:50',
            'ga4_credentials_json' => 'required|string',
        ]);

        // Validate JSON structure
        $credentials = json_decode($request->ga4_credentials_json, true);

        if (! $credentials || ! is_array($credentials)) {
            return response()->json(['message' => 'Gecersiz JSON formati.'], 422);
        }

        if (($credentials['type'] ?? '') !== 'service_account') {
            return response()->json(['message' => 'JSON dosyasi "type": "service_account" icermelidir.'], 422);
        }

        if (empty($credentials['client_email']) || empty($credentials['private_key'])) {
            return response()->json(['message' => 'JSON dosyasinda client_email ve private_key alanlari gereklidir.'], 422);
        }

        // Encrypt and save credentials to file
        $path = storage_path("app/ga4/{$tenant->id}.json.enc");
        file_put_contents($path, Crypt::encryptString($request->ga4_credentials_json));
        chmod($path, 0600);

        // Remove legacy plaintext file if exists
        $legacyPath = storage_path("app/ga4/{$tenant->id}.json");
        if (file_exists($legacyPath)) {
            unlink($legacyPath);
        }

        // Save property ID to tenant settings
        $existing = $tenant->settings ?? [];
        $existing['ga4_property_id'] = $request->ga4_property_id;
        $tenant->update(['settings' => $existing]);

        return response()->json([
            'message' => 'GA4 ayarlari kaydedildi.',
            'settings' => $tenant->fresh()->settings,
        ]);
    }

    public function ga4Status(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenant($request);

        if (! $tenant) {
            return response()->json(['connected' => false, 'message' => 'Tenant bulunamadi.']);
        }

        $service = GoogleAnalyticsService::fromTenant($tenant);

        if (! $service->isAvailable()) {
            return response()->json(['connected' => false, 'message' => 'GA4 yapilandirmasi eksik.']);
        }

        $result = $service->testConnection();

        return response()->json([
            'connected' => $result['success'],
            'message' => $result['message'],
        ]);
    }

    /**
     * Resolve the target tenant:
     * - Normal user → own tenant
     * - Super admin with X-Tenant-Id → that tenant
     * - Super admin without header → null (no tenant context)
     */
    private function resolveTenant(Request $request): ?Tenant
    {
        $user = $request->user();

        if ($user->tenant_id) {
            return $user->tenant;
        }

        // Super admin: check X-Tenant-Id header
        if ($request->hasHeader('X-Tenant-Id')) {
            return Tenant::find((int) $request->header('X-Tenant-Id'));
        }

        return null;
    }
}
