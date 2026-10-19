<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Demo/sunum tenant'ı için gerçekçi kozmetik e-ticaret verisi üretir.
 *
 * GÜVENLİK: yalnızca --tenant ile verilen tenant'a yazar; ürettiği veri
 * tamamen kurgusaldır (example.com e-postaları, gerçek olmayan telefonlar)
 * ve tenant'ın gönderim kill-switch'leri kapalı tutulur.
 *
 *   php artisan demo:seed --tenant=7 --fresh
 */
class DemoSeedTenant extends Command
{
    protected $signature = 'demo:seed
        {--tenant= : Hedef tenant ID (zorunlu)}
        {--fresh : Önce bu tenant\'ın mevcut demo verisini temizle}
        {--only= : Yalnızca belirtilen bölümler (virgülle): catalog,customers,orders,inbox,calls,returns,reviews,wheel,marketing,chat,search,rec,social,ads,feed,knowledge}';

    protected $description = 'Sunum/demo tenant\'ına gerçekçi kozmetik e-ticaret verisi yükler';

    /** Sipariş/etkinlik üretiminin kapsadığı gün sayısı. */
    private const DAYS = 180;

    private int $tenantId;
    private array $catalog = [];
    private array $people = [];
    private array $content = [];

    /** channel type => id */
    private array $ch = [];
    /** seed edilen ürün satırları (id, sku, name, price, ...) */
    private array $products = [];
    /** product_id => [variant rows] */
    private array $variants = [];
    /** customer rows */
    private array $customers = [];
    /** order rows */
    private array $orders = [];
    /** agent user ids */
    private array $agents = [];

    private string $imgBase;

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        if ($tenantId <= 0) {
            $this->error('--tenant zorunlu.');

            return 1;
        }

        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        if (! $tenant) {
            $this->error("Tenant {$tenantId} bulunamadı.");

            return 1;
        }

        // Canlı müşteri verisini korumak için tek emniyet: adı/slug'ı demo
        // olmayan tenant'lara yazmadan önce açık onay iste.
        if (! $this->confirmTarget($tenant)) {
            return 1;
        }

        $this->tenantId = $tenantId;
        $this->imgBase = rtrim(config('app.url'), '/').'/demo/test-firma/products';
        $this->catalog = require database_path('demo/catalog.php');
        $this->people = require database_path('demo/people.php');
        $this->content = require database_path('demo/content.php');

        mt_srand(20260907);

        if ($this->option('fresh')) {
            $this->purge();
        }

        $only = $this->option('only')
            ? array_map('trim', explode(',', $this->option('only')))
            : null;

        $steps = [
            'settings' => fn () => $this->seedSettings(),
            'channels' => fn () => $this->seedChannels(),
            'users' => fn () => $this->seedUsers(),
            'catalog' => fn () => $this->seedCatalog(),
            'customers' => fn () => $this->seedCustomers(),
            'orders' => fn () => $this->seedOrders(),
            'reviews' => fn () => $this->seedReviews(),
            'inbox' => fn () => $this->seedInbox(),
            'calls' => fn () => $this->seedCalls(),
            'returns' => fn () => $this->seedReturns(),
            'wheel' => fn () => $this->seedWheel(),
            'marketing' => fn () => $this->seedMarketing(),
            'chat' => fn () => $this->seedChat(),
            'search' => fn () => $this->seedSearch(),
            'rec' => fn () => $this->seedRecommendations(),
            'social' => fn () => $this->seedSocial(),
            'ads' => fn () => $this->seedAds(),
            'feed' => fn () => $this->seedFeed(),
            'knowledge' => fn () => $this->seedKnowledge(),
            'extras' => fn () => $this->seedExtras(),
        ];

        // Bu adımlar her koşuda çalışır: diğer bölümler bunların ürettiği
        // belleğe (ürün/müşteri/sipariş listeleri) dayanır. Veri zaten varsa
        // yeniden yazmazlar, yalnızca belleğe alırlar.
        $always = ['settings', 'channels', 'users', 'catalog', 'customers', 'orders'];

        foreach ($steps as $key => $fn) {
            if ($only && ! in_array($key, $only, true) && ! in_array($key, $always, true)) {
                continue;
            }
            // --only verilmişse zorunlu adımlar veriyi tekrar yazmasın diye
            // yalnızca bellek doldurma modunda çalışır.
            $t0 = microtime(true);
            $result = $fn();
            $ms = (int) ((microtime(true) - $t0) * 1000);
            $this->line(sprintf('  %-12s %s (%d ms)', $key, $result, $ms));
        }

        $this->newLine();
        $this->info("Demo verisi hazır — tenant #{$this->tenantId} ({$tenant->name}).");

        return 0;
    }

    private function confirmTarget(object $tenant): bool
    {
        $safe = str_contains(strtolower($tenant->name), 'test')
            || str_contains(strtolower($tenant->name), 'demo')
            || str_contains(strtolower((string) $tenant->slug), 'test')
            || str_contains(strtolower((string) $tenant->slug), 'demo');

        if ($safe) {
            return true;
        }

        return $this->confirm(
            "DİKKAT: '{$tenant->name}' bir demo tenant'ı gibi görünmüyor. Demo verisi yazılsın mı?",
            false
        );
    }

    // ── yardımcılar ────────────────────────────────────────────────────────

    private function pick(array $arr)
    {
        return $arr[mt_rand(0, count($arr) - 1)];
    }

    /** Yeni siparişlere doğru ağırlıklı rastgele gün (son günler daha yoğun). */
    private function skewedDaysAgo(int $max): int
    {
        $r = mt_rand(0, 10000) / 10000;

        return (int) floor($max * ($r ** 1.7));
    }

    private function ts(int $daysAgo, ?int $hour = null): Carbon
    {
        return Carbon::now()
            ->subDays($daysAgo)
            ->setTime($hour ?? mt_rand(8, 23), mt_rand(0, 59), mt_rand(0, 59));
    }

    private function j($v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function img(int $n): string
    {
        return sprintf('%s/p%03d.jpg', $this->imgBase, $n);
    }

    private function phone(int $i): string
    {
        // 555 0xx xx xx — operatörlerde tahsis edilmemiş demo aralığı
        return sprintf('905550%06d', $i % 1000000);
    }

    private function slugify(string $s): string
    {
        $tr = ['ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g',
            'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c', 'é' => 'e'];
        $s = strtr($s, $tr);
        $s = preg_replace('/[^a-zA-Z0-9]+/', '-', $s);

        return trim(strtolower($s), '-');
    }

    private function fill(string $tpl, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $tpl = str_replace('{'.$k.'}', (string) $v, $tpl);
        }

        return $tpl;
    }

    /** Büyük listeleri parça parça insert eder. */
    private function insertChunked(string $table, array $rows, int $size = 500): int
    {
        foreach (array_chunk($rows, $size) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        return count($rows);
    }

    // ── temizlik ───────────────────────────────────────────────────────────

    /** Bu tenant'a ait tüm modül verisini çocuktan ebeveyne doğru siler. */
    private function purge(): void
    {
        $t = $this->tenantId;

        DB::table('product_set_items')
            ->whereIn('product_set_id', DB::table('product_sets')->where('tenant_id', $t)->pluck('id'))
            ->delete();

        $ordered = [
            'rec_events', 'rec_copurchases', 'search_events', 'search_queries', 'search_synonyms',
            'chat_messages', 'chat_sessions', 'chat_rollout_visitors',
            'marketing_campaign_recipients', 'marketing_list_contacts', 'marketing_campaigns',
            'marketing_lists', 'marketing_segments', 'marketing_journeys', 'marketing_messages',
            'marketing_profiles', 'marketing_suppressions', 'marketing_email_templates',
            'iys_contacts',
            'wheel_spins', 'wheel_leads', 'wheel_slices', 'wheel_campaigns',
            'return_refunds', 'return_request_events', 'return_request_items', 'return_attachments', 'return_requests',
            'comment_replies', 'comments', 'messages', 'conversations',
            'email_messages', 'email_suppressions', 'calls',
            'product_reviews', 'product_review_summaries',
            'product_favorites', 'stock_alerts', 'web_push_subscriptions',
            'feed_ai_contents', 'feed_mappings', 'feed_transformations', 'feed_profiles', 'feed_sources',
            'product_feed_overrides', 'product_ga4_cache',
            'category_suggestions', 'product_platform_categories', 'platform_categories',
            'ai_training_data', 'ai_response_logs', 'knowledge_base', 'quick_replies',
            'meta_ad_recommendations', 'meta_strategy_runs', 'meta_strategies', 'meta_ab_tests',
            'social_posts', 'social_plans', 'social_assets',
            'tiktok_contents', 'tiktok_scan_videos', 'tiktok_trends',
            'ad_intel_ads', 'ad_intel_searches',
            'creative_videos', 'enhanced_images', 'enhanced_catalogs', 'creative_templates',
            'product_sets',
            'order_items', 'orders',
            'product_variants', 'products',
            'customers', 'channel_stats',
        ];

        foreach ($ordered as $table) {
            try {
                DB::table($table)->where('tenant_id', $t)->delete();
            } catch (\Throwable $e) {
                $this->warn("  temizlik atlandı: {$table} ({$e->getMessage()})");
            }
        }

        DB::table('users')->where('tenant_id', $t)->where('email', 'like', '%@testfirma.demo')->delete();

        $this->line('  temizlik     önceki demo verisi silindi');
    }

    // ── 1. tenant ayarları ─────────────────────────────────────────────────

    private function seedSettings(): string
    {
        $row = DB::table('tenants')->where('id', $this->tenantId)->first();
        $settings = json_decode($row->settings ?? '{}', true) ?: [];
        $domain = 'www.lumea-demo.com';

        $settings = array_merge($settings, [
            // 8 ürünün tamamı açık — sunumda hiçbir menü gizli kalmasın
            'products' => ['inbox', 'call', 'return', 'comments', 'store', 'ads', 'social', 'marketing'],
            'ecommerce_platform' => 'ticimax',
            'storefront_url' => 'https://'.$domain,
            'shop_url' => 'https://'.$domain,
            'cart_url' => 'https://'.$domain.'/sepet',
            'timezone' => 'Europe/Istanbul',
            'language' => 'tr',
            'default_language' => 'tr',
            'notification_email' => 'destek@testfirma.demo',
            'working_hours' => ['start' => '09:00', 'end' => '18:00'],
            'demo_tenant' => true,

            // ── GÖNDERİM KİLİTLERİ ────────────────────────────────────────
            // Demo verisi gerçek WhatsApp/SMS/e-posta gönderimi tetiklemesin.
            'order_notify_enabled' => false,
            'cart_recovery_enabled' => false,
            'wheel_reminder_enabled' => false,
            'stock_alert_widget_enabled' => true,
            'email_enabled' => false,
            'sms_enabled' => false,
            'whatsapp_enabled' => false,
            'push_enabled' => false,
            'iys' => ['enabled' => false],

            // ── Kargo/teslimat widget'ı ───────────────────────────────────
            'shipping_widget_enabled' => true,
            'shipping_widget_domains' => $domain,
            'shipping_widget_selector' => '.product-detail-shipping',
            'shipping_cutoff_time' => '16:00',
            'shipping_days' => '1,2,3,4,5',
            'shipping_urgent_minutes' => 120,
            'shipping_friday_extension' => false,
            'shipping_day_cutoffs' => json_encode([
                '1' => ['t' => '16:00', 'prev' => false],
                '2' => ['t' => '16:00', 'prev' => false],
                '3' => ['t' => '16:00', 'prev' => false],
                '4' => ['t' => '16:00', 'prev' => false],
                '5' => ['t' => '16:00', 'prev' => false],
            ], JSON_UNESCAPED_UNICODE),

            // ── Duyuru widget'ı ───────────────────────────────────────────
            'announce_widget_enabled' => true,
            'announce_widget_domains' => $domain,
            'announce_topbar_enabled' => true,
            'announce_topbar_text' => '750 TL üzeri siparişlerde KARGO ÜCRETSİZ',
            'announce_topbar_link_text' => 'Kampanyalar',
            'announce_topbar_url' => 'https://'.$domain.'/kampanyalar',
            'announce_topbar_bg' => '#2E2A26',
            'announce_topbar_fg' => '#FFFFFF',
            'announce_topbar_dismissible' => true,
            'announce_bell_enabled' => true,
            'announce_bell_position' => 'bottom-right',
            'announce_bell_items' => json_encode([
                ['id' => '1', 'title' => 'Cilt Bakımı Setlerinde %25', 'text' => '4 adımlık Glow Ritüeli seti şimdi indirimde.', 'url' => 'https://'.$domain.'/setler', 'starts_at' => '', 'ends_at' => ''],
                ['id' => '2', 'title' => 'Yeni: Nude Dreams Paleti', 'text' => '12 tonluk yeni far paletimiz stoklarda.', 'url' => 'https://'.$domain.'/makyaj', 'starts_at' => '', 'ends_at' => ''],
                ['id' => '3', 'title' => 'Her Siparişe 2 Numune', 'text' => 'Tüm siparişlerde 2 ürün numunesi hediye.', 'url' => '', 'starts_at' => '', 'ends_at' => ''],
            ], JSON_UNESCAPED_UNICODE),
            'announce_trust_enabled' => true,
            'announce_trust_selector' => '.product-detail-trust',
            'announce_trust_items' => json_encode([
                ['icon' => 'truck', 'title' => 'Kargo Bedava', 'subtitle' => '750 TL ve Üzeri'],
                ['icon' => 'zap', 'title' => 'Aynı Gün Kargo', 'subtitle' => '16:00\'ya kadar'],
                ['icon' => 'check', 'title' => 'Kolay İade', 'subtitle' => '30 Gün İade Hakkı'],
            ], JSON_UNESCAPED_UNICODE),

            // ── Akıllı arama widget'ı ─────────────────────────────────────
            'search_widget_enabled' => true,
            'search_widget_domains' => $domain,
            'search_widget_input' => '#site-search input',
            'search_widget_trigger' => '#site-search,.mobile-search-item',
            'search_widget_template' => 'default',
            'search_require_stock' => false,

            // ── Yorum widget'ı ────────────────────────────────────────────
            'review_widget_enabled' => true,
            'review_widget_domains' => $domain,
            'review_auto_full_hide' => false,
            'review_auto_full_hide_threshold' => 3,
            'review_negative_reduction' => 0,

            // ── Site chatbot ──────────────────────────────────────────────
            'chat_widget_enabled' => true,
            'chat_widget_domains' => $domain,
            'chat_widget_template' => 'default',
            'chat_bot_name' => 'Luméa Asistan',
            'chat_greeting_style' => 'friendly',
            'chat_launcher_style' => 'bubble',
            'chat_answer_mode' => 'grounded',
            'chat_min_confidence' => 0.6,
            'chat_allow_faq' => true,
            'chat_allow_products' => true,
            'chat_allow_order_status' => true,
            'chat_ask_detail' => true,
            'chat_handoff_enabled' => true,
            'chat_hours_enabled' => true,
            'chat_hours' => json_encode([
                ['open' => '09:00', 'close' => '18:00'],
                ['open' => '09:00', 'close' => '18:00'],
                ['open' => '09:00', 'close' => '18:00'],
                ['open' => '09:00', 'close' => '18:00'],
                ['open' => '09:00', 'close' => '18:00'],
                ['open' => '10:00', 'close' => '14:00'],
                null,
            ], JSON_UNESCAPED_UNICODE),
            'chat_rollout_mode' => 'all',
            'chat_rollout_limit' => 0,
            'chat_order_notes' => 'Siparişler hafta içi 16:00\'ya kadar aynı gün kargolanır, teslimat 1-3 iş günüdür.',

            // ── Çark ──────────────────────────────────────────────────────
            'wheel_widget_enabled' => true,
            'wheel_widget_domains' => $domain,

            // ── Ürün önerisi vitrinleri ───────────────────────────────────
            'rec_widget_domains' => $domain,
            'rec_cart_enabled' => true,
            'rec_cart_title' => 'Bunları da beğenebilirsiniz',
            'rec_cart_subtitle' => 'Rutininizi tamamlayan ürünler',
            'rec_cart_cta' => 'Sepete Ekle',
            'rec_cart_badge' => 'Sık birlikte alınır',
            'rec_cart_max_products' => 4,
            'rec_cart_timer_seconds' => 0,
            'rec_checkout_enabled' => true,
            'rec_checkout_title' => 'Siparişinizi tamamlamadan önce',
            'rec_checkout_subtitle' => 'Son bir dokunuş ister misiniz?',
            'rec_checkout_cta' => 'Ekle',
            'rec_checkout_continue' => 'Ödemeye devam et',
            'rec_checkout_badge' => 'Size özel',
            'rec_checkout_max_products' => 4,
            'rec_checkout_timer_seconds' => 0,

            // ── Gelince haber ver ─────────────────────────────────────────
            'stock_alert_widget_domains' => $domain,

            // ── Kategori sıralaması ───────────────────────────────────────
            'category_ranking_enabled' => false,

            // ── İade modülü ───────────────────────────────────────────────
            'returns' => [
                'enabled' => true,
                'min_items' => 1,
                'return_enabled' => true,
                'cancel_enabled' => true,
                'defect_enabled' => true,
                'return_window_days' => 30,
                'shipment_expire_days' => 14,
                'defect_max_days' => 365,
                'refund_shipping' => false,
                'refund_shipping_cancel' => true,
                'allow_store_credit' => true,
                'promote_store_credit' => true,
                'allow_original_refund' => true,
                'staff_review_hours' => 48,
                'manager_review_hours' => 48,
                'returnable_statuses' => [7],
                'cancellable_statuses' => [0, 3, 4, 5],
                'excluded_skus' => [],
                'excluded_categories' => [],
                'cancel_block_if_shipped' => true,
                'cancel_block_if_prepared' => false,
                'block_if_open_request_exists' => true,
                'require_condition_declaration' => true,
                'refund_installment_fee' => true,
                'campaign_discount_recovery' => true,
                'defect_reject_reasons' => [
                    'Kullanım kaynaklı açılma/kullanım tespit edildi',
                    'Üretim kusuru tespit edilemedi',
                    'Fotoğraflar değerlendirme için yetersiz',
                    'Ürün garanti/değerlendirme kapsamı dışında',
                ],
            ],

            // ── AI ────────────────────────────────────────────────────────
            'ai_enabled' => true,
            'ai_model' => 'claude-sonnet-5',

            // ── Pazarlama frekans/sessiz saat kuralları ───────────────────
            'marketing_check_mail_consent' => true,
            'marketing_check_sms_consent' => true,
            'marketing_quiet_enabled' => true,
            'marketing_send_start' => '09:00',
            'marketing_send_end' => '21:00',
            'marketing_freq_enabled' => true,
            'marketing_freq_per_day' => 1,
            'marketing_freq_per_week' => 3,

            // ── Sosyal medya modülü ───────────────────────────────────────
            'social' => [
                'enabled' => true,
                'channels' => ['instagram', 'facebook'],
                'monthly_posts' => 20,
                'monthly_reels' => 8,
                'monthly_stories' => 40,
                'approval_mode' => 'manual',
                'website_text' => 'www.lumea-demo.com',
                'logo_url' => '',
                'drive_folder' => '',
                'brand' => [
                    'colors' => ['primary' => '#C9A227', 'secondary' => '#2E2A26'],
                    'caption_style' => [
                        'tone' => 'sıcak, bilgilendirici, uzman',
                        'language' => 'tr',
                        'cta_style' => 'Rutininize ekleyin',
                        'emoji_usage' => 'light',
                        'avg_hashtags' => 5,
                        'sample_hashtags' => ['Lumea', 'CiltBakimi', 'SkincareTR', 'GlowUp', 'VeganKozmetik'],
                    ],
                    'post' => [
                        'show_logo' => true,
                        'show_website' => true,
                        'website_text' => 'www.lumea-demo.com',
                        'logo_position' => 'top_left',
                        'style_notes' => 'nötr tonlar, temiz arka plan, ürün odaklı flat lay',
                    ],
                    'story' => [
                        'show_logo' => true,
                        'show_link' => true,
                        'show_price' => true,
                        'show_product_name' => true,
                        'style_notes' => 'minimal, bol beyaz alan, altın vurgu',
                    ],
                ],
            ],

            'product_feed' => [
                'feed_enabled' => true,
                'feed_title' => 'Luméa',
                'default_gender' => 'unisex',
                'default_age_group' => 'adult',
                'exclude_out_of_stock' => false,
                'default_google_category' => 'Health & Beauty > Personal Care > Cosmetics',
            ],
        ]);

        // Demo tenant'ta gerçek API anahtarı tutulmaz.
        unset($settings['openai_api_key'], $settings['claude_api_key'], $settings['allowed_pages']);

        DB::table('tenants')->where('id', $this->tenantId)->update([
            'settings' => $this->j($settings),
            'updated_at' => now(),
        ]);

        return '8 ürün + tüm widget ayarları açık, gönderim kilitleri kapalı';
    }

    // ── 2. kanallar ────────────────────────────────────────────────────────

    private function seedChannels(): string
    {
        // is_active: yalnızca kimlik bilgisi gerektirmeyen senkron komutlarının
        // güvenle atladığı mesaj kanalları açılır. E-ticaret kanalı KAPALI
        // kalır — crm:sync-* komutları aktif ticimax kanalı arıyor.
        $defs = [
            ['type' => 'ticimax-ecommerce', 'name' => 'Luméa E-Ticaret', 'category' => 'ecommerce', 'active' => false],
            ['type' => 'whatsapp', 'name' => 'WhatsApp', 'category' => 'messaging', 'active' => true],
            ['type' => 'instagram-message', 'name' => 'Instagram', 'category' => 'messaging', 'active' => true],
            ['type' => 'facebook-message', 'name' => 'Facebook Messenger', 'category' => 'messaging', 'active' => true],
            ['type' => 'instagram-comment', 'name' => 'Instagram Yorumlar', 'category' => 'social', 'active' => true],
            ['type' => 'facebook-comment', 'name' => 'Facebook Yorumlar', 'category' => 'social', 'active' => true],
            ['type' => 'email', 'name' => 'E-posta', 'category' => 'email', 'active' => true],
            ['type' => 'ticimax-ticket', 'name' => 'Site Destek Talepleri', 'category' => 'messaging', 'active' => true],
            ['type' => 'webchat', 'name' => 'Site Chat', 'category' => 'messaging', 'active' => true],
            ['type' => 'netgsm-call', 'name' => 'Çağrı Merkezi', 'category' => 'call', 'active' => false],
            ['type' => 'sms', 'name' => 'SMS', 'category' => 'messaging', 'active' => false],
            ['type' => 'trendyol-product', 'name' => 'Trendyol', 'category' => 'marketplace', 'active' => false],
            ['type' => 'hepsiburada-product', 'name' => 'Hepsiburada', 'category' => 'marketplace', 'active' => false],
        ];

        foreach ($defs as $d) {
            $existing = DB::table('channels')
                ->where('tenant_id', $this->tenantId)->where('type', $d['type'])->first();

            $payload = [
                'name' => $d['name'],
                'category' => $d['category'],
                'is_active' => $d['active'],
                'settings' => $this->j(['demo' => true]),
                'last_synced_at' => now()->subMinutes(mt_rand(3, 40)),
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('channels')->where('id', $existing->id)->update($payload);
                $this->ch[$d['type']] = $existing->id;
            } else {
                $this->ch[$d['type']] = DB::table('channels')->insertGetId($payload + [
                    'tenant_id' => $this->tenantId,
                    'type' => $d['type'],
                    'created_at' => now(),
                ]);
            }
        }

        return count($defs).' kanal hazır (e-ticaret + çağrı + SMS pasif)';
    }

    // ── 3. kullanıcılar ────────────────────────────────────────────────────

    private function seedUsers(): string
    {
        foreach ($this->people['agents'] as $a) {
            $existing = DB::table('users')->where('email', $a['email'])->first();
            $payload = [
                'name' => $a['name'],
                'role' => $a['role'],
                'status' => 'active',
                'can_use_ai' => true,
                'return_role' => $a['role'] === 'admin' ? 'manager' : 'staff',
                'last_login_at' => now()->subHours(mt_rand(1, 40)),
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('users')->where('id', $existing->id)->update($payload);
                $this->agents[] = $existing->id;
            } else {
                $this->agents[] = DB::table('users')->insertGetId($payload + [
                    'tenant_id' => $this->tenantId,
                    'email' => $a['email'],
                    'password' => Hash::make('DemoSunum2026!'),
                    'email_verified_at' => now(),
                    'created_at' => now()->subMonths(8),
                ]);
            }
        }

        // Tenant'ın mevcut adminleri de iade akışını sunabilsin
        DB::table('users')
            ->where('tenant_id', $this->tenantId)
            ->where('role', 'admin')
            ->where('return_role', 'none')
            ->update(['return_role' => 'manager', 'can_use_ai' => true]);

        return count($this->agents).' temsilci (şifre: DemoSunum2026!)';
    }

    // ── 4. katalog: kategori ağacı + ürünler + varyantlar ──────────────────

    private function seedCatalog(): string
    {
        $chId = $this->ch['ticimax-ecommerce'];

        // Yeniden çalıştırmada (ör. --only) mevcut katalog belleğe alınır,
        // ikinci kez yazılmaz.
        if (DB::table('products')->where('tenant_id', $this->tenantId)->exists()) {
            $this->loadCatalogIntoMemory();

            return 'mevcut katalog kullanıldı (yeniden yazılmadı)';
        }

        // Kategori ağacı — catalog.php'deki path'lerden türetilir.
        $paths = [];
        foreach ($this->catalog as $p) {
            $segments = array_map('trim', explode('>', $p['path']));
            for ($i = 1; $i <= count($segments); $i++) {
                $paths[implode(' > ', array_slice($segments, 0, $i))] = true;
            }
        }
        $paths = array_keys($paths);
        sort($paths);

        $catIdByPath = [];
        $nextPlatformId = 9000;
        $catRows = [];
        foreach ($paths as $path) {
            $segments = explode(' > ', $path);
            $parentPath = count($segments) > 1
                ? implode(' > ', array_slice($segments, 0, -1))
                : null;

            $pid = $nextPlatformId++;
            $catIdByPath[$path] = $pid;

            $catRows[] = [
                'tenant_id' => $this->tenantId,
                'platform_category_id' => $pid,
                'parent_platform_id' => $parentPath ? $catIdByPath[$parentPath] : 0,
                'name' => end($segments),
                'path' => $path,
                'depth' => count($segments) - 1,
                'url' => '/kategori/'.$this->slugify($path),
                'is_active' => true,
                'is_assignable' => count($segments) > 1,
                'synced_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('platform_categories')->insert($catRows);

        // children_count / product_count sonradan güncellenir
        $catDbId = DB::table('platform_categories')
            ->where('tenant_id', $this->tenantId)
            ->pluck('id', 'platform_category_id')->all();

        // Ürünler
        $productRows = [];
        $pid = 5000;
        foreach ($this->catalog as $i => $p) {
            $pid++;
            $tierRoll = mt_rand(1, 100);
            $tier = $tierRoll <= 15 ? 'A' : ($tierRoll <= 42 ? 'B' : ($tierRoll <= 78 ? 'C' : 'D'));

            $hasTrendyol = mt_rand(1, 100) <= 62;
            $tyPrice = $hasTrendyol ? round($p['disc'] * (mt_rand(88, 118) / 100), 2) : null;

            $productRows[] = [
                'tenant_id' => $this->tenantId,
                'channel_id' => $chId,
                'platform_product_id' => (string) $pid,
                'name' => $p['name'],
                'description' => $p['desc'],
                'sku' => $p['sku'],
                'barcode' => '868'.str_pad((string) (1000000000 + $i * 7717), 10, '0', STR_PAD_LEFT),
                'brand' => 'Luméa',
                'category' => $p['cat'],
                'category_path' => $p['path'],
                'price' => $p['price'],
                'discounted_price' => $p['disc'],
                'cost_price' => round($p['price'] * 0.36, 2),
                'currency' => 'TRY',
                'tax_rate' => 20.00,
                'stock_quantity' => $p['stock'],
                'images' => $this->j(array_map(fn ($n) => $this->img($n), $p['img'])),
                'url' => '/urun/'.$this->slugify($p['name']).'-'.$pid,
                'is_active' => true,
                'metadata' => $this->j([
                    'marka_id' => 1,
                    'satis_birimi' => 'Adet',
                    'vegan' => mt_rand(1, 10) <= 9,
                    'cruelty_free' => true,
                    'dermatolojik_test' => true,
                ]),
                'specifications' => $this->j($p['specs']),
                'google_product_category' => 'Health & Beauty > Personal Care > Cosmetics',
                'performance_label' => 'TIER/'.$tier,
                'performance_score' => round(match ($tier) {
                    'A' => mt_rand(1400, 2400) / 100,
                    'B' => mt_rand(700, 1400) / 100,
                    'C' => mt_rand(250, 700) / 100,
                    default => mt_rand(10, 250) / 100,
                }, 2),
                'trendyol_price' => $tyPrice,
                'trendyol_original_price' => $tyPrice ? round($p['price'], 2) : null,
                'trendyol_in_stock' => $hasTrendyol ? ($p['stock'] > 0) : null,
                'trendyol_rating' => $hasTrendyol ? round(mt_rand(390, 495) / 100, 3) : null,
                'trendyol_product_url' => $hasTrendyol
                    ? 'https://www.trendyol.com/lumea/'.$this->slugify($p['name']).'-p-'.(700000000 + $pid)
                    : null,
                'trendyol_discount_percent' => $hasTrendyol ? round((1 - $p['disc'] / $p['price']) * 100, 2) : null,
                'trendyol_price_synced_at' => $hasTrendyol ? now()->subHours(mt_rand(2, 20)) : null,
                'platform_created_at' => now()->subDays(mt_rand(60, 400)),
                'platform_updated_at' => now()->subDays(mt_rand(0, 20)),
                'created_at' => now()->subDays(mt_rand(60, 400)),
                'updated_at' => now(),
            ];
        }
        DB::table('products')->insert($productRows);

        $this->products = DB::table('products')
            ->where('tenant_id', $this->tenantId)
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        // Varyantlar
        $variantRows = [];
        $vid = 80000;
        foreach ($this->products as $idx => $prod) {
            $def = $this->catalog[$idx];
            $count = count($def['variants']);
            foreach ($def['variants'] as $vi => $attrs) {
                $vid++;
                // Stok ana ürün stoğundan paylaştırılır; bazı varyantlar tükenir
                $share = (int) floor($prod['stock_quantity'] / max(1, $count));
                $vStock = $prod['stock_quantity'] === 0
                    ? 0
                    : ($vi > 0 && mt_rand(1, 100) <= 18 ? 0 : max(0, $share + mt_rand(-8, 14)));

                $variantRows[] = [
                    'tenant_id' => $this->tenantId,
                    'product_id' => $prod['id'],
                    'platform_variant_id' => (string) $vid,
                    'sku' => $prod['sku'].'-'.str_pad((string) ($vi + 1), 2, '0', STR_PAD_LEFT),
                    'barcode' => '868'.str_pad((string) (2000000000 + $vid * 31), 10, '0', STR_PAD_LEFT),
                    'price' => $prod['price'],
                    'discounted_price' => $prod['discounted_price'],
                    'cost_price' => $prod['cost_price'],
                    'stock_quantity' => $vStock,
                    'attributes' => $this->j($attrs),
                    'is_active' => true,
                    'metadata' => $this->j([]),
                    'created_at' => now()->subDays(mt_rand(60, 400)),
                    'updated_at' => now(),
                ];
            }
        }
        DB::table('product_variants')->insert($variantRows);

        foreach (DB::table('product_variants')->where('tenant_id', $this->tenantId)->orderBy('id')->get() as $v) {
            $this->variants[$v->product_id][] = (array) $v;
        }

        // Ürün ↔ kategori eşlemesi (ppc.platform_category_id = pc.platform_category_id)
        $ppcRows = [];
        $sira = 0;
        foreach ($this->products as $idx => $prod) {
            $segments = array_map('trim', explode('>', $this->catalog[$idx]['path']));
            for ($i = 1; $i <= count($segments); $i++) {
                $path = implode(' > ', array_slice($segments, 0, $i));
                $ppcRows[] = [
                    'tenant_id' => $this->tenantId,
                    'product_id' => $prod['id'],
                    'platform_product_id' => (int) $prod['platform_product_id'],
                    'platform_category_id' => $catIdByPath[$path],
                    'sira' => ++$sira,
                    'is_primary' => $i === count($segments),
                    'synced_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        $this->insertChunked('product_platform_categories', $ppcRows);

        // Kategori sayaçları
        DB::statement('
            UPDATE platform_categories pc SET product_count = COALESCE((
                SELECT COUNT(*) FROM product_platform_categories ppc
                WHERE ppc.tenant_id = pc.tenant_id
                  AND ppc.platform_category_id = pc.platform_category_id
            ), 0)
            WHERE pc.tenant_id = ?
        ', [$this->tenantId]);

        DB::statement('
            UPDATE platform_categories pc SET children_count = COALESCE((
                SELECT COUNT(*) FROM platform_categories c2
                WHERE c2.tenant_id = pc.tenant_id
                  AND c2.parent_platform_id = pc.platform_category_id
            ), 0)
            WHERE pc.tenant_id = ?
        ', [$this->tenantId]);

        return sprintf('%d ürün, %d varyant, %d kategori',
            count($productRows), count($variantRows), count($catRows));
    }

    // ── 5. müşteriler ──────────────────────────────────────────────────────

    /** --only ile tekrar çalıştırıldığında katalogu bellekte kurar. */
    private function loadCatalogIntoMemory(): void
    {
        $this->products = DB::table('products')->where('tenant_id', $this->tenantId)
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        $this->variants = [];
        foreach (DB::table('product_variants')->where('tenant_id', $this->tenantId)->orderBy('id')->get() as $v) {
            $this->variants[$v->product_id][] = (array) $v;
        }
    }

    private function seedCustomers(): string
    {
        $chId = $this->ch['ticimax-ecommerce'];

        if (DB::table('customers')->where('tenant_id', $this->tenantId)->exists()) {
            $this->customers = DB::table('customers')->where('tenant_id', $this->tenantId)
                ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

            return 'mevcut müşteriler kullanıldı';
        }

        // Şehirleri ağırlıklarına göre havuza aç
        $cityPool = [];
        foreach ($this->people['cities'] as $c) {
            for ($i = 0; $i < $c[2]; $i++) {
                $cityPool[] = $c;
            }
        }

        $rows = [];
        $used = [];
        for ($i = 1; $i <= 480; $i++) {
            // Kozmetikte kadın müşteri ağırlıklı gerçekçi dağılım
            $isFemale = mt_rand(1, 100) <= 78;
            $first = $isFemale ? $this->pick($this->people['female']) : $this->pick($this->people['male']);
            $last = $this->pick($this->people['last']);
            $city = $this->pick($cityPool);

            $emailBase = $this->slugify($first.'.'.$last);
            $email = $emailBase.'@example.com';
            if (isset($used[$email])) {
                $email = $emailBase.$i.'@example.com';
            }
            $used[$email] = true;

            $created = $this->ts(mt_rand(10, 720));

            $rows[] = [
                'tenant_id' => $this->tenantId,
                'channel_id' => $chId,
                'platform_customer_id' => (string) (400000 + $i),
                'platform_type' => 'ticimax',
                'platform_id' => (string) (400000 + $i),
                'name' => $first,
                'surname' => $last,
                'email' => $email,
                'phone' => $this->phone($i),
                'gender' => $isFemale ? 1 : 2,
                'birth_date' => Carbon::now()->subYears(mt_rand(19, 58))->subDays(mt_rand(0, 364))->toDateString(),
                'city' => $city[0],
                'district' => $this->pick($city[1]),
                'address' => $this->pick($city[1]).' Mah. '.$this->pick(['Bahar', 'Gül', 'Zambak', 'Çınar', 'Menekşe', 'Lale', 'Papatya']).' Sok. No:'.mt_rand(1, 90).' D:'.mt_rand(1, 20),
                'member_type' => mt_rand(1, 100) <= 82 ? 'Üye' : 'Misafir',
                'is_active' => mt_rand(1, 100) <= 96,
                'mail_consent' => mt_rand(1, 100) <= 71,
                'sms_consent' => mt_rand(1, 100) <= 63,
                'total_orders' => 0,
                'total_spent' => 0,
                'loyalty_points' => mt_rand(0, 100) <= 60 ? mt_rand(0, 1200) : 0,
                'metadata' => $this->j(['demo' => true]),
                'platform_created_at' => $created,
                'last_login_at' => mt_rand(1, 100) <= 78 ? $this->ts(mt_rand(0, 90)) : null,
                'created_at' => $created,
                'updated_at' => now(),
            ];
        }

        $this->insertChunked('customers', $rows);

        $this->customers = DB::table('customers')
            ->where('tenant_id', $this->tenantId)
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return count($rows).' müşteri';
    }

    // ── 6. siparişler ──────────────────────────────────────────────────────

    private function seedOrders(): string
    {
        $chId = $this->ch['ticimax-ecommerce'];

        if (DB::table('orders')->where('tenant_id', $this->tenantId)->exists()) {
            $this->orders = DB::table('orders')->where('tenant_id', $this->tenantId)
                ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

            return 'mevcut siparişler kullanıldı';
        }

        $statusMap = [
            [7, 'Teslim Edildi', 46],
            [6, 'Kargoya Verildi', 12],
            [4, 'Paketleniyor', 5],
            [0, 'Siparişiniz Alındı', 9],
            [3, 'Ödeme Bekliyor', 3],
            [13, 'İade Ödemesi Yapıldı', 6],
            [11, 'İade Talebi Alındı', 3],
            [9, 'İade Edildi', 2],
            [8, 'İptal Edildi', 3],
            [17, 'Kısmi İade Yapıldı', 1],
            [18, 'Teslim Edilemedi', 1],
        ];
        $statusPool = [];
        foreach ($statusMap as $s) {
            for ($i = 0; $i < $s[2]; $i++) {
                $statusPool[] = $s;
            }
        }

        // Ürün seçim havuzu — performans etiketi yüksek ürünler daha çok satsın
        $productPool = [];
        foreach ($this->products as $p) {
            $weight = match ($p['performance_label']) {
                'TIER/A' => 9, 'TIER/B' => 5, 'TIER/C' => 3, default => 1,
            };
            for ($i = 0; $i < $weight; $i++) {
                $productPool[] = $p;
            }
        }

        $orderRows = [];
        $itemRows = [];
        $orderNo = 100000;
        $sources = ['Web', 'Web', 'Web', 'Mobil Uygulama', 'Mobil Uygulama', 'Instagram', 'Google'];

        foreach ($this->customers as $ci => $cust) {
            // Müşteri başına sipariş adedi: çoğu 1-2, azınlığı sadık
            $roll = mt_rand(1, 100);
            $count = $roll <= 22 ? 0 : ($roll <= 62 ? 1 : ($roll <= 84 ? 2 : ($roll <= 95 ? mt_rand(3, 5) : mt_rand(6, 11))));

            for ($n = 0; $n < $count; $n++) {
                $orderNo++;
                $daysAgo = $this->skewedDaysAgo(self::DAYS);
                $date = $this->ts($daysAgo);
                [$status, $label] = $this->pick($statusPool);

                // Çok yeni siparişler henüz teslim edilmiş olamaz
                if ($daysAgo < 3 && in_array($status, [7, 13, 9, 17], true)) {
                    [$status, $label] = [0, 'Siparişiniz Alındı'];
                }

                $itemCount = mt_rand(1, 100) <= 52 ? 1 : (mt_rand(1, 100) <= 70 ? 2 : mt_rand(3, 4));
                $picked = [];
                $subtotal = 0;
                $lines = [];

                for ($k = 0; $k < $itemCount; $k++) {
                    $prod = $this->pick($productPool);
                    if (isset($picked[$prod['id']])) {
                        continue;
                    }
                    $picked[$prod['id']] = true;

                    $vars = $this->variants[$prod['id']] ?? [];
                    $var = $vars ? $this->pick($vars) : null;
                    $qty = mt_rand(1, 100) <= 86 ? 1 : 2;
                    $unit = (float) $prod['discounted_price'];
                    $total = round($unit * $qty, 2);
                    $subtotal += $total;

                    $attrs = $var ? json_decode($var['attributes'], true) : [];
                    $imgs = json_decode($prod['images'], true);

                    $lines[] = [
                        'tenant_id' => $this->tenantId,
                        'platform_product_id' => $prod['platform_product_id'],
                        'platform_variant_id' => $var['platform_variant_id'] ?? null,
                        'product_name' => $prod['name'],
                        'variant_info' => $attrs ? implode(' / ', array_values($attrs)) : null,
                        'sku' => $var['sku'] ?? $prod['sku'],
                        'barcode' => $var['barcode'] ?? $prod['barcode'],
                        'quantity' => $qty,
                        'unit_price' => $unit,
                        'total_price' => $total,
                        'discount_amount' => 0,
                        'status' => in_array($status, [9, 13], true) ? 9 : 0,
                        'status_label' => in_array($status, [9, 13], true) ? 'İade Edildi' : $label,
                        'product_id' => $prod['id'],
                        'product_variant_id' => $var['id'] ?? null,
                        'image_url' => $imgs[0] ?? null,
                        'tax_rate' => 20.00,
                        'tax_amount' => round($total - $total / 1.20, 2),
                        'created_at' => $date,
                        'updated_at' => $date,
                    ];
                }

                if (! $lines) {
                    continue;
                }

                $couponRoll = mt_rand(1, 100);
                $coupon = $couponRoll <= 9 ? $this->pick(['HOSGELDIN15', 'CILT20', 'KARGOBEDAVA', 'CARK10', 'MAKYAJ25']) : null;
                $discount = $coupon ? round($subtotal * (mt_rand(10, 25) / 100), 2) : 0;
                $shipping = ($subtotal - $discount) >= 750 ? 0 : 79.90;
                $payType = mt_rand(1, 100) <= 88 ? 0 : 1; // 0 = online, 1 = kapıda
                if ($payType === 1) {
                    $shipping += 49.90;
                }
                $totalAmount = round($subtotal - $discount + $shipping, 2);
                $paid = in_array($status, [3], true) ? 0 : $totalAmount;

                $city = $cust['city'];
                $addr = [
                    'ad_soyad' => $cust['name'].' '.$cust['surname'],
                    'il' => $city,
                    'ilce' => $cust['district'],
                    'adres' => $cust['address'],
                    'telefon' => $cust['phone'],
                ];

                $shipped = in_array($status, [6, 7, 13, 9, 11, 17, 18], true);

                $orderRows[] = [
                    'tenant_id' => $this->tenantId,
                    'channel_id' => $chId,
                    'customer_id' => $cust['id'],
                    'platform_order_id' => (string) $orderNo,
                    'platform_customer_id' => $cust['platform_customer_id'],
                    'order_no' => 'LM'.$orderNo,
                    'order_code' => 'LM'.$orderNo,
                    'status' => $status,
                    'status_label' => $label,
                    'subtotal' => $subtotal,
                    'discount_amount' => $discount,
                    'shipping_cost' => $shipping,
                    'gift_card_amount' => 0,
                    'points_discount' => 0,
                    'tax_amount' => round($totalAmount - $totalAmount / 1.20, 2),
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paid,
                    'currency' => 'TRY',
                    'customer_name' => $cust['name'].' '.$cust['surname'],
                    'customer_email' => $cust['email'],
                    'cargo_company' => $shipped ? $this->pick($this->people['cargo']) : null,
                    'cargo_tracking_no' => $shipped ? (string) mt_rand(100000000000, 999999999999) : null,
                    'payment_type' => $payType,
                    'payment_status' => $paid > 0 ? 1 : 0,
                    'order_source' => $this->pick($sources),
                    'billing_address' => $this->j($addr),
                    'shipping_address' => $this->j($addr),
                    'ip_address' => '88.'.mt_rand(10, 250).'.'.mt_rand(1, 250).'.'.mt_rand(1, 250),
                    'items_count' => count($lines),
                    'order_date' => $date,
                    'status_updated_at' => $date->copy()->addHours(mt_rand(2, 72)),
                    'platform_updated_at' => $date->copy()->addHours(mt_rand(2, 72)),
                    'coupon_code' => $coupon,
                    'payments' => $this->j([[
                        'yontem' => $payType === 1 ? 'Kapıda Ödeme' : $this->pick(['Kredi Kartı', 'Kredi Kartı', 'Banka Kartı', 'Havale/EFT']),
                        'tutar' => $paid,
                        'onaylandi' => $paid > 0,
                    ]]),
                    'metadata' => $this->j(['demo' => true, 'lines' => count($lines)]),
                    'created_at' => $date,
                    'updated_at' => $date,
                ];

                // order_id insert sonrası bağlanacak
                $itemRows[$orderNo] = $lines;
            }
        }

        $this->insertChunked('orders', $orderRows);

        $orderIds = DB::table('orders')
            ->where('tenant_id', $this->tenantId)
            ->pluck('id', 'platform_order_id')->all();

        $flat = [];
        foreach ($itemRows as $pOrderNo => $lines) {
            $oid = $orderIds[(string) $pOrderNo] ?? null;
            if (! $oid) {
                continue;
            }
            foreach ($lines as $line) {
                $flat[] = $line + ['order_id' => $oid];
            }
        }
        $this->insertChunked('order_items', $flat, 800);

        // Müşteri toplamlarını gerçek siparişlerden hesapla
        DB::statement('
            UPDATE customers c SET
                total_orders = COALESCE(o.cnt, 0),
                total_spent = COALESCE(o.sum, 0)
            FROM (
                SELECT customer_id, COUNT(*) cnt, SUM(total_amount) sum
                FROM orders WHERE tenant_id = ? AND status NOT IN (8) GROUP BY customer_id
            ) o
            WHERE c.id = o.customer_id AND c.tenant_id = ?
        ', [$this->tenantId, $this->tenantId]);

        $this->orders = DB::table('orders')
            ->where('tenant_id', $this->tenantId)
            ->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        return sprintf('%d sipariş, %d kalem', count($orderRows), count($flat));
    }

    // ── 7. ürün yorumları ──────────────────────────────────────────────────

    private function seedReviews(): string
    {
        $reviewRows = [];
        $summaryRows = [];
        $rid = 1;

        foreach ($this->products as $prod) {
            $weight = match ($prod['performance_label']) {
                'TIER/A' => mt_rand(14, 26), 'TIER/B' => mt_rand(7, 15),
                'TIER/C' => mt_rand(2, 8), default => mt_rand(0, 3),
            };
            if ($weight === 0) {
                continue;
            }

            $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            $contentId = 700000000 + (int) $prod['platform_product_id'];

            for ($i = 0; $i < $weight; $i++) {
                [$rate, $text] = $this->pick($this->content['reviews']);
                $dist[$rate]++;
                $isFemale = mt_rand(1, 100) <= 80;
                $first = $isFemale ? $this->pick($this->people['female']) : $this->pick($this->people['male']);
                $last = $this->pick($this->people['last']);
                $when = $this->ts(mt_rand(2, 260));

                $reviewRows[] = [
                    'tenant_id' => $this->tenantId,
                    'product_id' => $prod['id'],
                    'trendyol_content_id' => $contentId,
                    'platform_review_id' => 'demo-'.$prod['id'].'-'.($rid++),
                    'rate' => $rate,
                    'user_full_name' => $first.' '.mb_substr($last, 0, 1).'**',
                    'comment' => $text,
                    'comment_date_text' => $when->translatedFormat('d F Y'),
                    'reviewed_at' => $when,
                    'images' => $this->j([]),
                    'like_count' => mt_rand(0, 34),
                    'seller_reply' => mt_rand(1, 100) <= 28 ? $this->pick($this->content['review_replies']) : null,
                    'created_at' => $when,
                    'updated_at' => $when,
                ];
            }

            $total = array_sum($dist);
            $avg = $total ? round(($dist[1] + 2 * $dist[2] + 3 * $dist[3] + 4 * $dist[4] + 5 * $dist[5]) / $total, 2) : 0;

            $summaryRows[] = [
                'tenant_id' => $this->tenantId,
                'product_id' => $prod['id'],
                'trendyol_content_id' => $contentId,
                'avg_rate' => $avg,
                'rate_count' => $total + mt_rand(0, 40),
                'comment_count' => $total,
                'rate_five' => $dist[5], 'rate_four' => $dist[4], 'rate_three' => $dist[3],
                'rate_two' => $dist[2], 'rate_one' => $dist[1],
                'reviews_synced_rating' => $avg,
                'ai_summary' => $avg >= 4.4
                    ? 'Müşteriler etkisinden ve hafif dokusundan çok memnun. En sık öne çıkan başlıklar: hızlı sonuç, kokusuz formül ve fiyat/performans. Sınırlı sayıda müşteri ambalaj boyutunun küçük olduğunu belirtiyor.'
                    : ($avg >= 3.8
                        ? 'Genel memnuniyet iyi seviyede. Olumlu yorumlar dokusu ve kullanım kolaylığı üzerine yoğunlaşıyor; eleştiriler etkinin geç görülmesi ve ambalaj konusunda.'
                        : 'Memnuniyet ortalama. Müşterilerin bir bölümü beklediği etkiyi göremediğini belirtiyor; ürün açıklamasında beklenti yönetimi güçlendirilebilir.'),
                'last_synced_at' => now()->subHours(mt_rand(1, 24)),
                'created_at' => now()->subDays(mt_rand(20, 200)),
                'updated_at' => now(),
            ];
        }

        $this->insertChunked('product_reviews', $reviewRows);
        $this->insertChunked('product_review_summaries', $summaryRows);

        return sprintf('%d yorum, %d ürün özeti', count($reviewRows), count($summaryRows));
    }

    // ── 8. gelen kutusu: konuşmalar, mesajlar, yorumlar, e-postalar ────────

    private function seedInbox(): string
    {
        $msgChannels = [
            'whatsapp' => 32,
            'instagram-message' => 24,
            'facebook-message' => 10,
            'email' => 16,
            'ticimax-ticket' => 11,
            'webchat' => 7,
        ];
        $chanPool = [];
        foreach ($msgChannels as $type => $w) {
            for ($i = 0; $i < $w; $i++) {
                $chanPool[] = $type;
            }
        }

        $scripts = $this->content['conversations'];
        $convRows = [];
        $pending = [];   // conversation index => [turns, vars, channelType, date]

        for ($i = 0; $i < 260; $i++) {
            $script = $scripts[$i % count($scripts)];
            $chanType = $this->pick($chanPool);
            $cust = $this->pick($this->customers);
            $custOrders = array_values(array_filter(
                $this->orders,
                fn ($o) => $o['customer_id'] === $cust['id']
            ));
            $order = $custOrders ? $this->pick($custOrders) : ($this->orders ? $this->pick($this->orders) : null);

            $daysAgo = $this->skewedDaysAgo(90);
            $start = $this->ts($daysAgo);

            $vars = [
                'ad' => $cust['name'],
                'siparis' => $order['order_no'] ?? 'LM100412',
                'urun' => $this->pick($this->products)['name'],
                'kargo' => $order['cargo_company'] ?? $this->pick($this->people['cargo']),
                'takip' => $order['cargo_tracking_no'] ?? (string) mt_rand(100000000000, 999999999999),
                'gun' => (string) mt_rand(4, 9),
                'tutar' => number_format((float) ($order['total_amount'] ?? 749.9), 2, ',', '.'),
                'kod' => $this->pick(['OZUR20', 'TELAFI15', 'SIZEOZEL20', 'GERIDON10']),
            ];

            $lastAt = $start->copy()->addMinutes(count($script['turns']) * mt_rand(3, 90));

            $convRows[] = [
                'tenant_id' => $this->tenantId,
                'channel_id' => $this->ch[$chanType],
                'customer_id' => $cust['id'],
                'type' => 'message',
                'status' => $script['status'],
                'is_urgent' => $script['urgent'],
                'is_read' => ! in_array($script['status'], ['open', 'pending'], true),
                'unread_count' => in_array($script['status'], ['open', 'pending'], true) ? 1 : 0,
                'last_message_at' => $lastAt,
                'assigned_to' => in_array($script['status'], ['resolved', 'closed'], true) ? $this->pick($this->agents) : null,
                'platform_conversation_id' => 'demo-conv-'.$i,
                'metadata' => $this->j(['topic' => $script['topic'], 'demo' => true]),
                'ai_suggestion' => null,
                'ai_suggestion_confidence' => null,
                'created_at' => $start,
                'updated_at' => $lastAt,
            ];

            $pending[] = ['script' => $script, 'vars' => $vars, 'start' => $start, 'chan' => $chanType, 'cust' => $cust];
        }

        $this->insertChunked('conversations', $convRows);

        $convIds = DB::table('conversations')
            ->where('tenant_id', $this->tenantId)->where('type', 'message')
            ->orderBy('id')->pluck('id')->all();

        $msgRows = [];
        $aiSuggestions = [];
        foreach ($pending as $idx => $p) {
            $cid = $convIds[$idx] ?? null;
            if (! $cid) {
                continue;
            }
            $at = $p['start']->copy();
            $lastCustomerAt = null;

            foreach ($p['script']['turns'] as $turn) {
                [$who, $text] = [$turn[0], $turn[1]];
                $isAi = ($turn[2] ?? false) === true;
                $content = $this->fill($text, $p['vars']);

                if ($who === 'c') {
                    $at = $at->copy()->addMinutes(mt_rand(1, 45));
                    $lastCustomerAt = $at->copy();
                    $msgRows[] = [
                        'tenant_id' => $this->tenantId,
                        'conversation_id' => $cid,
                        'sender_type' => 'customer',
                        'sender_id' => null,
                        'content' => $content,
                        'is_ai_generated' => false,
                        'ai_original_content' => null,
                        'platform_message_id' => 'demo-m-'.$cid.'-'.count($msgRows),
                        'metadata' => $this->j([]),
                        'handling_time_seconds' => null,
                        'penalty_points' => 0,
                        'created_at' => $at,
                        'updated_at' => $at,
                    ];
                } else {
                    $wait = mt_rand(60, 2400);
                    $at = ($lastCustomerAt ?? $at)->copy()->addSeconds($wait);
                    // AI önerisi temsilci tarafından bazen düzenlenir
                    $edited = $isAi && mt_rand(1, 100) <= 35;
                    $msgRows[] = [
                        'tenant_id' => $this->tenantId,
                        'conversation_id' => $cid,
                        'sender_type' => 'agent',
                        'sender_id' => $this->pick($this->agents),
                        'content' => $content,
                        'is_ai_generated' => $isAi,
                        'ai_original_content' => $isAi
                            ? ($edited ? mb_substr($content, 0, max(30, (int) (mb_strlen($content) * 0.8))).'…' : $content)
                            : null,
                        'platform_message_id' => 'demo-m-'.$cid.'-'.count($msgRows),
                        'metadata' => $this->j([]),
                        'handling_time_seconds' => $wait,
                        'penalty_points' => 0,
                        'created_at' => $at,
                        'updated_at' => $at,
                    ];
                }
            }

            // Cevaplanmamış konuşmalara AI önerisi bırak
            if (in_array($p['script']['status'], ['open', 'pending'], true)) {
                $aiSuggestions[$cid] = [
                    'text' => $this->suggestionFor($p['script']['topic'], $p['vars']),
                    'conf' => mt_rand(72, 96) / 100,
                ];
            }
        }

        $this->insertChunked('messages', $msgRows, 800);

        foreach ($aiSuggestions as $cid => $s) {
            DB::table('conversations')->where('id', $cid)->update([
                'ai_suggestion' => $s['text'],
                'ai_suggestion_confidence' => $s['conf'],
            ]);
        }

        // ── Sosyal medya yorumları ────────────────────────────────────────
        $commentConvRows = [];
        $commentMeta = [];
        for ($i = 0; $i < 130; $i++) {
            $chanType = mt_rand(1, 100) <= 72 ? 'instagram-comment' : 'facebook-comment';
            $cust = $this->pick($this->customers);
            [$text, $status, $reply] = $this->content['comments'][$i % count($this->content['comments'])];
            [$postTitle, $postUrl] = $this->pick($this->content['posts']);
            $when = $this->ts($this->skewedDaysAgo(60));

            $commentConvRows[] = [
                'tenant_id' => $this->tenantId,
                'channel_id' => $this->ch[$chanType],
                'customer_id' => $cust['id'],
                'type' => 'comment',
                'status' => $status === 'replied' ? 'resolved' : 'open',
                'is_urgent' => false,
                'is_read' => $status === 'replied',
                'unread_count' => $status === 'replied' ? 0 : 1,
                'last_message_at' => $when,
                'assigned_to' => $status === 'replied' ? $this->pick($this->agents) : null,
                'platform_conversation_id' => 'demo-cmt-conv-'.$i,
                'metadata' => $this->j(['demo' => true]),
                'created_at' => $when,
                'updated_at' => $when,
            ];
            $commentMeta[] = compact('text', 'status', 'reply', 'postTitle', 'postUrl', 'when', 'cust');
        }
        $this->insertChunked('conversations', $commentConvRows);

        $cmtConvIds = DB::table('conversations')
            ->where('tenant_id', $this->tenantId)->where('type', 'comment')
            ->orderBy('id')->pluck('id')->all();

        $cmtRows = [];
        foreach ($commentMeta as $idx => $m) {
            $cid = $cmtConvIds[$idx] ?? null;
            if (! $cid) {
                continue;
            }
            $cmtRows[] = [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $cid,
                'post_title' => $m['postTitle'],
                'post_url' => $m['postUrl'],
                'platform_comment_id' => 'demo-c-'.$idx,
                'platform_post_id' => 'demo-post-'.($idx % 6),
                'content' => $m['text'],
                'status' => $m['status'],
                'created_at' => $m['when'],
                'updated_at' => $m['when'],
            ];
        }
        $this->insertChunked('comments', $cmtRows);

        $cmtIds = DB::table('comments')->where('tenant_id', $this->tenantId)->orderBy('id')->pluck('id')->all();
        $replyRows = [];
        foreach ($commentMeta as $idx => $m) {
            if ($m['status'] !== 'replied' || ! $m['reply'] || ! isset($cmtIds[$idx])) {
                continue;
            }
            $isAi = mt_rand(1, 100) <= 55;
            $replyRows[] = [
                'tenant_id' => $this->tenantId,
                'comment_id' => $cmtIds[$idx],
                'user_id' => $this->pick($this->agents),
                'content' => $this->fill($m['reply'], ['ad' => $m['cust']['name']]),
                'is_ai_generated' => $isAi,
                'ai_original_content' => $isAi ? $this->fill($m['reply'], ['ad' => $m['cust']['name']]) : null,
                'platform_reply_id' => 'demo-r-'.$idx,
                'created_at' => $m['when']->copy()->addMinutes(mt_rand(4, 220)),
                'updated_at' => $m['when']->copy()->addMinutes(mt_rand(4, 220)),
            ];
        }
        $this->insertChunked('comment_replies', $replyRows);

        // ── E-posta arşivi ────────────────────────────────────────────────
        $emailConvIds = DB::table('conversations')
            ->where('tenant_id', $this->tenantId)
            ->where('channel_id', $this->ch['email'])
            ->orderBy('id')->get(['id', 'customer_id', 'created_at']);

        $categories = ['customer_request', 'customer_request', 'customer_request', 'notification', 'other'];
        $emailRows = [];
        foreach ($emailConvIds as $c) {
            $cust = DB::table('customers')->where('id', $c->customer_id)->first();
            $subject = $this->pick([
                'Siparişim hakkında', 'Kargo takip bilgisi', 'İade talebi', 'Ürün sorusu',
                'Fatura talebi', 'Sipariş iptali', 'Teslimat adresi değişikliği', 'Ürün önerisi rica ediyorum',
            ]);
            $when = Carbon::parse($c->created_at);

            $emailRows[] = [
                'tenant_id' => $this->tenantId,
                'channel_id' => $this->ch['email'],
                'message_id' => '<demo-'.$c->id.'@example.com>',
                'from_email' => $cust->email ?? 'musteri@example.com',
                'from_name' => trim(($cust->name ?? '').' '.($cust->surname ?? '')),
                'to_email' => 'destek@testfirma.demo',
                'subject' => $subject,
                'body_text' => $subject.' konusunda bilgi rica ediyorum. İyi çalışmalar.',
                'ai_category' => $this->pick($categories),
                'conversation_id' => $c->id,
                'direction' => 'incoming',
                'processed_at' => $when,
                'created_at' => $when,
                'updated_at' => $when,
            ];
        }
        $this->insertChunked('email_messages', $emailRows);

        return sprintf('%d konuşma, %d mesaj, %d yorum, %d e-posta',
            count($convRows) + count($commentConvRows), count($msgRows), count($cmtRows), count($emailRows));
    }

    private function suggestionFor(string $topic, array $vars): string
    {
        return match (true) {
            str_contains($topic, 'Abonelik') => 'Merhaba '.$vars['ad'].' Hanım, şu anda otomatik abonelik seçeneğimiz bulunmuyor ancak sık kullandığınız ürünler için "Gelince Haber Ver" ve favori listesi özelliklerimizden yararlanabilirsiniz. Abonelik modelini yakında devreye almayı planlıyoruz 🌸',
            str_contains($topic, 'Fiyat farkı') => 'Merhaba '.$vars['ad'].' Hanım, o fiyat 48 saatlik kampanya fiyatımızdı ve dün gece sona erdi. Size özel '.$vars['kod'].' kodunu tanımladım — sepetinizde kampanya fiyatına yakın bir indirim sağlayacak.',
            str_contains($topic, 'akne') => 'Merhaba '.$vars['ad'].' Hanım, akne izleri için Niasinamid %10 Serum + AHA-BHA Temizleme Jeli ikilisini öneriyoruz; gündüz mutlaka SPF 50+ kullanmanız iz açılmasını hızlandırır. Aktif akne varsa dermatoloğa danışmanızı da öneririz.',
            default => 'Merhaba '.$vars['ad'].' Hanım, mesajınız için teşekkür ederiz. Konuyu inceliyorum, en kısa sürede detaylı bilgi ile size dönüş yapacağım 🌸',
        };
    }

    // ── 9. çağrılar ────────────────────────────────────────────────────────

    private function seedCalls(): string
    {
        $rows = [];
        for ($i = 0; $i < 140; $i++) {
            $cust = $this->pick($this->customers);
            [$cat, $summary, $sentiment] = $this->pick($this->content['calls']);
            $inbound = mt_rand(1, 100) <= 76;
            $answered = mt_rand(1, 100) <= 83;
            $when = $this->ts($this->skewedDaysAgo(60), mt_rand(9, 18));
            $duration = $answered ? mt_rand(45, 640) : 0;

            $rows[] = [
                'tenant_id' => $this->tenantId,
                'channel_id' => $this->ch['netgsm-call'],
                'customer_id' => $cust['id'],
                'common_id' => 'demo-call-'.$i,
                'caller' => $inbound ? $cust['phone'] : '902123334455',
                'called' => $inbound ? '902123334455' : $cust['phone'],
                'extension' => $answered ? (string) $this->pick([101, 102, 103, 104]) : null,
                'direction' => $inbound ? 'inbound' : 'outbound',
                'status' => $answered ? 'answered' : 'missed',
                'started_at' => $when,
                'duration' => $duration,
                'ring_duration' => mt_rand(3, 28),
                'transcription' => $answered ? $summary : null,
                'ai_summary' => $answered ? $summary : null,
                'ai_sentiment' => $answered ? $sentiment : null,
                'ai_category' => $cat,
                'ai_metadata' => $this->j(['demo' => true, 'konu' => $cat]),
                'transcribed_at' => $answered ? $when->copy()->addMinutes(6) : null,
                'source' => 'netgsm',
                'created_at' => $when,
                'updated_at' => $when,
            ];
        }
        $this->insertChunked('calls', $rows);

        return count($rows).' çağrı kaydı';
    }

    // ── 10. iade / iptal talepleri ─────────────────────────────────────────

    private function seedReturns(): string
    {
        // Yalnızca teslim edilmiş / iade sürecindeki siparişlerden talep açılır
        $eligible = array_values(array_filter(
            $this->orders,
            fn ($o) => in_array((int) $o['status'], [7, 13, 11, 9, 17], true)
        ));
        if (! $eligible) {
            return 'uygun sipariş yok';
        }
        shuffle($eligible);

        $plan = [
            ['return', 'refunded', 'original', 16],
            ['return', 'refunded', 'store_credit', 4],
            ['return', 'awaiting_shipment', 'original', 7],
            ['return', 'in_transit', 'original', 3],
            ['return', 'received', 'original', 4],
            ['return', 'staff_approved', 'original', 4],
            ['return', 'refunding', 'original', 2],
            ['return', 'pending', 'original', 3],
            ['return', 'rejected', 'original', 3],
            ['return', 'cancelled_by_customer', 'original', 3],
            ['cancel', 'refunded', 'original', 4],
            ['cancel', 'completed', 'none', 3],
            ['defect', 'staff_approved', 'original', 2],
            ['defect', 'rejected', 'original', 1],
        ];

        $reqRows = [];
        $meta = [];
        $i = 0;
        foreach ($plan as [$type, $status, $method, $count]) {
            for ($n = 0; $n < $count; $n++) {
                $order = $eligible[$i % count($eligible)];
                $i++;
                $items = DB::table('order_items')->where('order_id', $order['id'])->get();
                if ($items->isEmpty()) {
                    continue;
                }

                $take = $items->count() > 1 && mt_rand(1, 100) <= 45
                    ? $items->take(mt_rand(1, $items->count() - 1))
                    : $items;
                $requested = round($take->sum(fn ($it) => (float) $it->total_price), 2);
                if ($requested <= 0) {
                    continue;
                }

                $created = Carbon::parse($order['order_date'])->addDays(mt_rand(3, 22));
                if ($created->isFuture()) {
                    $created = Carbon::now()->subDays(mt_rand(1, 6));
                }

                $isDone = in_array($status, ['refunded', 'completed'], true);
                $shipped = in_array($status, ['awaiting_shipment', 'in_transit', 'received', 'refunding', 'refunded'], true) && $type !== 'cancel';
                $staffOk = ! in_array($status, ['pending', 'cancelled_by_customer'], true);
                $mgrOk = in_array($status, ['refunding', 'refunded', 'completed'], true);

                [$reasonCode, $reasonNote] = $this->pick($this->content['return_reasons']);

                $reqRows[] = [
                    'tenant_id' => $this->tenantId,
                    'channel_id' => $this->ch['ticimax-ecommerce'],
                    'type' => $type,
                    'public_code' => 'lm'.str_pad((string) (10400000 + $i * 137), 9, '0', STR_PAD_LEFT),
                    'status' => $status,
                    'platform_order_id' => $order['platform_order_id'],
                    'order_no' => $order['order_no'],
                    'customer_name' => $order['customer_name'],
                    'customer_email' => $order['customer_email'],
                    'customer_phone' => '9055501'.str_pad((string) ($i % 100000), 5, '0', STR_PAD_LEFT),
                    'currency' => 'TRY',
                    'refund_method' => $method,
                    'requested_amount' => $requested,
                    'approved_amount' => $staffOk ? $requested : null,
                    'refunded_amount' => $isDone && $method !== 'none' ? $requested : 0,
                    'paid_amount_snapshot' => $order['paid_amount'],
                    'eligibility_snapshot' => $this->j([
                        'status' => (int) $order['status'],
                        'status_label' => $order['status_label'],
                        'can_return' => true,
                        'can_cancel' => $type === 'cancel',
                        'return_window_days' => 30,
                        'deadline' => Carbon::parse($order['order_date'])->addDays(30)->toDateString(),
                        'reasons' => [],
                    ]),
                    'staff_approved_by' => $staffOk ? $this->pick($this->agents) : null,
                    'staff_approved_at' => $staffOk ? $created->copy()->addHours(mt_rand(2, 30)) : null,
                    'manager_approved_by' => $mgrOk ? $this->agents[count($this->agents) - 1] : null,
                    'manager_approved_at' => $mgrOk ? $created->copy()->addDays(mt_rand(2, 7)) : null,
                    'reject_reason' => $status === 'rejected'
                        ? $this->pick(['Ürün kullanılmış olarak teslim alındı', 'Hijyen kuralları gereği açılmış kozmetik ürün iade alınamıyor', 'İade süresi (30 gün) aşılmış'])
                        : null,
                    'rejected_by' => $status === 'rejected' ? $this->pick($this->agents) : null,
                    'rejected_at' => $status === 'rejected' ? $created->copy()->addDays(mt_rand(1, 4)) : null,
                    'cargo_provider' => $shipped ? 'surat-kargo' : null,
                    'cargo_tracking_code' => $shipped ? (string) mt_rand(10000000000000, 99999999999999) : null,
                    'shipment_status' => $shipped ? 'created' : null,
                    'received_at' => in_array($status, ['received', 'refunding', 'refunded'], true)
                        ? $created->copy()->addDays(mt_rand(3, 9)) : null,
                    'metadata' => $this->j([
                        'demo' => true,
                        'rules' => ['return_window_days' => 30, 'allow_store_credit' => true, 'shipment_expire_days' => 14],
                        'customer_address' => [
                            'city' => $this->pick($this->people['cities'])[0],
                            'address' => 'Demo adres bilgisi',
                        ],
                    ]),
                    'created_at' => $created,
                    'updated_at' => $isDone ? $created->copy()->addDays(mt_rand(5, 12)) : $created->copy()->addDays(mt_rand(0, 3)),
                ];

                $meta[] = [
                    'items' => $take, 'requested' => $requested, 'created' => $created,
                    'status' => $status, 'type' => $type, 'method' => $method,
                    'staffOk' => $staffOk, 'mgrOk' => $mgrOk, 'shipped' => $shipped,
                    'reason' => [$reasonCode, $reasonNote],
                ];
            }
        }

        $this->insertChunked('return_requests', $reqRows);

        $ids = DB::table('return_requests')->where('tenant_id', $this->tenantId)
            ->orderBy('id')->pluck('id')->all();

        $itemRows = [];
        $eventRows = [];
        $refundRows = [];

        foreach ($meta as $idx => $m) {
            $rid = $ids[$idx] ?? null;
            if (! $rid) {
                continue;
            }

            foreach ($m['items'] as $it) {
                $itemRows[] = [
                    'tenant_id' => $this->tenantId,
                    'return_request_id' => $rid,
                    'platform_line_id' => (string) $it->id,
                    'platform_product_id' => $it->platform_product_id,
                    'platform_variant_id' => $it->platform_variant_id,
                    'product_name' => $it->product_name,
                    'sku' => $it->sku,
                    'barcode' => $it->barcode,
                    'variant_info' => $it->variant_info,
                    'image_url' => $it->image_url,
                    'qty_ordered' => $it->quantity,
                    'qty_requested' => $it->quantity,
                    'qty_approved' => $m['staffOk'] ? $it->quantity : null,
                    'unit_price' => $it->unit_price,
                    'line_discount_share' => 0,
                    'refundable_amount' => $it->total_price,
                    'reason_code' => $m['reason'][0],
                    'reason_note' => $m['reason'][1],
                    'item_condition' => $this->pick(['unopened', 'unopened', 'opened']),
                    'metadata' => $this->j([]),
                    'created_at' => $m['created'],
                    'updated_at' => $m['created'],
                ];
            }

            $addEvent = function (string $action, ?string $from, ?string $to, $amount, Carbon $at, string $actor = 'staff') use (&$eventRows, $rid) {
                $eventRows[] = [
                    'tenant_id' => $this->tenantId,
                    'return_request_id' => $rid,
                    'actor_type' => $actor,
                    'actor_id' => $actor === 'customer' ? null : $this->pick($this->agents),
                    'action' => $action,
                    'from_status' => $from,
                    'to_status' => $to,
                    'amount' => $amount,
                    'payload' => $this->j(['demo' => true]),
                    'ip_address' => '88.'.mt_rand(10, 250).'.'.mt_rand(1, 250).'.'.mt_rand(1, 250),
                    'created_at' => $at,
                ];
            };

            $t = $m['created']->copy();
            $addEvent('submit', null, 'pending', $m['requested'], $t, 'customer');
            if ($m['staffOk']) {
                $t = $t->copy()->addHours(mt_rand(2, 30));
                $addEvent('staff_approve', 'pending', 'staff_approved', $m['requested'], $t);
            }
            if ($m['shipped']) {
                $t = $t->copy()->addHours(mt_rand(1, 6));
                $addEvent('cargo_created', 'staff_approved', 'awaiting_shipment', null, $t);
                $addEvent('sms_cargo_code', null, null, null, $t->copy()->addMinutes(2));
            }
            if (in_array($m['status'], ['received', 'refunding', 'refunded'], true)) {
                $t = $t->copy()->addDays(mt_rand(2, 7));
                $addEvent('sms_received', 'in_transit', 'received', null, $t);
            }
            if ($m['mgrOk']) {
                $t = $t->copy()->addDays(mt_rand(1, 3));
                $addEvent('manager_approve', 'received', 'refunding', $m['requested'], $t);
            }
            if ($m['status'] === 'refunded') {
                $t = $t->copy()->addHours(mt_rand(1, 20));
                $addEvent('refund', 'refunding', 'refunded', $m['requested'], $t);
                $addEvent('sms_refunded', null, null, null, $t->copy()->addMinutes(3));

                $refundRows[] = [
                    'tenant_id' => $this->tenantId,
                    'return_request_id' => $rid,
                    'provider' => $m['method'] === 'store_credit' ? 'coupon' : 'iyzico',
                    'idempotency_key' => 'refund:demo:'.$rid,
                    'amount' => $m['requested'],
                    'currency' => 'TRY',
                    'status' => 'succeeded',
                    'provider_txn_id' => $m['method'] === 'store_credit' ? null : 'tbp'.mt_rand(10000000, 99999999),
                    'coupon_code' => $m['method'] === 'store_credit' ? 'IADE'.strtoupper(substr(md5((string) $rid), 0, 6)) : null,
                    'attempts' => 1,
                    'request_payload' => $this->j(['demo' => true]),
                    'response_payload' => $this->j(['status' => 'success']),
                    'processed_at' => $t,
                    'created_at' => $t,
                    'updated_at' => $t,
                ];
            }
            if ($m['status'] === 'rejected') {
                $addEvent('reject', 'pending', 'rejected', null, $t->copy()->addDays(1));
            }
            if ($m['status'] === 'cancelled_by_customer') {
                $addEvent('cancel_by_customer', 'pending', 'cancelled_by_customer', null, $t->copy()->addHours(6), 'customer');
            }
            if ($m['status'] === 'completed') {
                $addEvent('auto_transition', 'processing', 'completed', null, $t->copy()->addDays(1), 'system');
            }
        }

        $this->insertChunked('return_request_items', $itemRows);
        $this->insertChunked('return_request_events', $eventRows, 800);
        $this->insertChunked('return_refunds', $refundRows);

        return sprintf('%d talep, %d kalem, %d olay, %d iade ödemesi',
            count($reqRows), count($itemRows), count($eventRows), count($refundRows));
    }

    // ── 11. çark kampanyası ────────────────────────────────────────────────

    private function seedWheel(): string
    {
        $campaignId = DB::table('wheel_campaigns')->insertGetId([
            'tenant_id' => $this->tenantId,
            'name' => 'Luméa Şans Çarkı',
            'slug' => 'lumea-sans-carki',
            'status' => 'active',
            'design' => $this->j([
                'primary' => '#C9A227', 'secondary' => '#2E2A26', 'text' => '#FFFFFF',
                'title' => 'Şansını dene, kazan! 🎁',
                'subtitle' => 'E-postanı bırak, sana özel indirimi çevir.',
            ]),
            'trigger_rules' => $this->j(['delay_seconds' => 12, 'scroll_percent' => 35, 'exit_intent' => true, 'once_per_days' => 30]),
            'form_fields' => $this->j(['full_name' => true, 'email' => true, 'phone' => true, 'kvkk' => true, 'comms' => true]),
            'starts_at' => now()->subDays(120),
            'ends_at' => now()->addDays(60),
            'coupon_provider' => 'ticimax',
            'coupon_valid_hours' => 72,
            'settings' => $this->j(['min_cart' => 500, 'demo' => true]),
            'created_at' => now()->subDays(120),
            'updated_at' => now(),
        ]);

        $slices = [
            ['%10 İndirim', 'percent', 10, 26, '#C9A227', true],
            ['%15 İndirim', 'percent', 15, 18, '#2E2A26', true],
            ['%20 İndirim', 'percent', 20, 9, '#C9A227', true],
            ['Kargo Bedava', 'shipping', 0, 20, '#2E2A26', true],
            ['50 TL İndirim', 'amount', 50, 12, '#C9A227', true],
            ['Numune Hediye', 'gift', 0, 8, '#2E2A26', true],
            ['%25 İndirim', 'percent', 25, 2, '#C9A227', true],
            ['Bu Sefer Olmadı', 'none', 0, 5, '#8A8580', false],
        ];
        $sliceIds = [];
        foreach ($slices as $n => $s) {
            $sliceIds[] = DB::table('wheel_slices')->insertGetId([
                'tenant_id' => $this->tenantId,
                'wheel_campaign_id' => $campaignId,
                'label' => $s[0],
                'prize_type' => $s[1],
                'prize_value' => $s[2],
                'weight' => $s[3],
                'color' => $s[4],
                'sort_order' => $n,
                'coupon_code_template' => $s[5] ? 'CARK{RANDOM}' : null,
                'stock_used' => 0,
                'settings' => $this->j(['is_win' => $s[5]]),
                'created_at' => now()->subDays(120),
                'updated_at' => now(),
            ]);
        }

        $weighted = [];
        foreach ($slices as $n => $s) {
            for ($i = 0; $i < $s[3]; $i++) {
                $weighted[] = $n;
            }
        }

        $leadRows = [];
        $spinPlan = [];
        for ($i = 0; $i < 340; $i++) {
            $cust = $this->pick($this->customers);
            $when = $this->ts($this->skewedDaysAgo(110));
            $leadRows[] = [
                'tenant_id' => $this->tenantId,
                'wheel_campaign_id' => $campaignId,
                'full_name' => $cust['name'].' '.$cust['surname'],
                'email' => $cust['email'],
                'phone' => $cust['phone'],
                'kvkk_consent' => true,
                'kvkk_consent_version' => 'v1',
                'kvkk_consent_text' => 'Kişisel verilerimin kampanya amacıyla işlenmesini kabul ediyorum.',
                'kvkk_consent_at' => $when,
                'comms_consent' => mt_rand(1, 100) <= 74,
                'comms_consent_text' => 'Ticari elektronik ileti almayı kabul ediyorum.',
                'comms_consent_at' => $when,
                'source_url' => 'https://www.lumea-demo.com/',
                'ip_address' => '88.'.mt_rand(10, 250).'.'.mt_rand(1, 250).'.'.mt_rand(1, 250),
                'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)',
                'device_fingerprint' => md5('demo'.$i),
                'metadata' => $this->j(['demo' => true]),
                'is_test' => false,
                'created_at' => $when,
                'updated_at' => $when,
            ];
            $spinPlan[] = [$this->pick($weighted), $when];
        }
        $this->insertChunked('wheel_leads', $leadRows);

        $leadIds = DB::table('wheel_leads')->where('tenant_id', $this->tenantId)->orderBy('id')->pluck('id')->all();

        $spinRows = [];
        foreach ($spinPlan as $idx => [$sliceIdx, $when]) {
            $s = $slices[$sliceIdx];
            $isWin = $s[5];
            $redeemed = $isWin && mt_rand(1, 100) <= 21;
            $spinRows[] = [
                'tenant_id' => $this->tenantId,
                'wheel_campaign_id' => $campaignId,
                'wheel_lead_id' => $leadIds[$idx] ?? null,
                'wheel_slice_id' => $sliceIds[$sliceIdx],
                'prize_label' => $s[0],
                'prize_type' => $s[1],
                'prize_value' => $s[2],
                'is_win' => $isWin,
                'coupon_code' => $isWin ? 'CARK'.strtoupper(substr(md5('c'.$idx), 0, 6)) : null,
                'coupon_provider' => 'ticimax',
                'coupon_status' => $isWin ? ($redeemed ? 'redeemed' : ($when->diffInHours(now()) > 72 ? 'expired' : 'active')) : 'none',
                'coupon_expires_at' => $isWin ? $when->copy()->addHours(72) : null,
                'redeemed_at' => $redeemed ? $when->copy()->addHours(mt_rand(2, 70)) : null,
                'ip_address' => '88.'.mt_rand(10, 250).'.'.mt_rand(1, 250).'.'.mt_rand(1, 250),
                'device_fingerprint' => md5('demo'.$idx),
                'metadata' => $this->j(['demo' => true]),
                'is_test' => false,
                'created_at' => $when,
                'updated_at' => $when,
            ];
        }
        $this->insertChunked('wheel_spins', $spinRows);

        // Kupon kullanımı ve ciro atfı, kupon kodunun siparişte görünmesine bağlı.
        // Kullanılmış işaretlenen çevirmelerin kodunu uygun siparişlere yaz.
        $redeemedSpins = DB::table('wheel_spins')
            ->where('tenant_id', $this->tenantId)
            ->where('coupon_status', 'redeemed')
            ->whereNotNull('coupon_code')
            ->get(['id', 'coupon_code', 'created_at']);

        $freeOrders = DB::table('orders')
            ->where('tenant_id', $this->tenantId)
            ->whereNull('coupon_code')
            ->where('payment_status', 1)
            ->orderBy('order_date')
            ->get(['id', 'order_date']);

        $usedOrders = [];
        foreach ($redeemedSpins as $spin) {
            foreach ($freeOrders as $o) {
                if (isset($usedOrders[$o->id]) || $o->order_date < $spin->created_at) {
                    continue;
                }
                DB::table('orders')->where('id', $o->id)->update(['coupon_code' => $spin->coupon_code]);
                $usedOrders[$o->id] = true;
                break;
            }
        }

        DB::statement('
            UPDATE wheel_slices ws SET stock_used = COALESCE((
                SELECT COUNT(*) FROM wheel_spins sp WHERE sp.wheel_slice_id = ws.id
            ), 0) WHERE ws.tenant_id = ?
        ', [$this->tenantId]);

        return sprintf('1 çark, %d dilim, %d katılımcı', count($slices), count($leadRows));
    }

    // ── 12. pazarlama ──────────────────────────────────────────────────────

    private function seedMarketing(): string
    {
        // Segmentler
        $segDefs = [
            ['VIP Müşteriler', ['min_orders' => 5, 'min_total' => 10000], 1],
            ['Yeni Müşteriler', ['first_order_within_days' => 30], 2],
            ['Uykuda Müşteriler', ['min_orders' => 1, 'last_order_older_days' => 90], 3],
            ['Cilt Bakımı Meraklıları', ['category_path_like' => 'Cilt Bakımı'], 4],
            ['Makyaj Müşterileri', ['category_path_like' => 'Makyaj'], 5],
            ['Tek Siparişliler', ['min_orders' => 1, 'max_orders' => 1], 6],
            ['Yüksek Sepetliler', ['min_avg_order' => 1500], 7],
            ['Tüm Üyeler', ['is_member' => true], 8],
        ];
        $segIds = [];
        foreach ($this->content['segments'] as $n => $s) {
            $def = $segDefs[$n] ?? $segDefs[0];
            $segIds[] = DB::table('marketing_segments')->insertGetId([
                'tenant_id' => $this->tenantId,
                'name' => $s[0],
                'description' => $s[1],
                'definition' => $this->j($def[1]),
                'is_system' => false,
                'sort' => $def[2],
                'created_at' => now()->subDays(mt_rand(40, 150)),
                'updated_at' => now(),
            ]);
        }

        // Pazarlama profilleri (segmentasyon tabanı)
        $profileRows = [];
        foreach ($this->customers as $c) {
            $orders = array_values(array_filter($this->orders, fn ($o) => $o['customer_id'] === $c['id']));
            $sum = array_sum(array_map(fn ($o) => (float) $o['total_amount'], $orders));
            $dates = array_map(fn ($o) => $o['order_date'], $orders);
            sort($dates);

            $profileRows[] = [
                'tenant_id' => $this->tenantId,
                'phone' => $c['phone'],
                'email' => $c['email'],
                'name' => $c['name'].' '.$c['surname'],
                'is_member' => $c['member_type'] === 'Üye',
                'orders_count' => count($orders),
                'refunded_count' => 0,
                'total_spent' => round($sum, 2),
                'max_order_amount' => $orders ? round(max(array_map(fn ($o) => (float) $o['total_amount'], $orders)), 2) : 0,
                'first_order_at' => $dates ? $dates[0] : null,
                'last_order_at' => $dates ? end($dates) : null,
                'first_source' => $orders ? $orders[0]['order_source'] : 'Web',
                'created_at' => $c['created_at'],
                'updated_at' => now(),
            ];
        }
        $this->insertChunked('marketing_profiles', $profileRows);

        // Liste (WhatsApp kampanyaları için)
        $listId = DB::table('marketing_lists')->insertGetId([
            'tenant_id' => $this->tenantId,
            'name' => 'İzinli WhatsApp Kitlesi',
            'description' => 'Ticari elektronik ileti izni veren müşteriler',
            'contacts_count' => 0,
            'created_at' => now()->subDays(90),
            'updated_at' => now(),
        ]);

        $listRows = [];
        foreach ($this->customers as $c) {
            if (! $c['sms_consent']) {
                continue;
            }
            $listRows[] = [
                'tenant_id' => $this->tenantId,
                'list_id' => $listId,
                'phone' => $c['phone'],
                'name' => $c['name'].' '.$c['surname'],
                'customer_id' => $c['id'],
                'meta' => $this->j([]),
                'created_at' => now()->subDays(mt_rand(10, 90)),
                'updated_at' => now(),
            ];
        }
        $this->insertChunked('marketing_list_contacts', $listRows);
        DB::table('marketing_lists')->where('id', $listId)->update(['contacts_count' => count($listRows)]);

        // Kampanyalar + alıcılar
        $campaignIds = [];
        $recipientRows = [];
        foreach ($this->content['campaigns'] as $n => $c) {
            [$name, $channel, $subject, $status] = $c;
            $started = now()->subDays(mt_rand(8, 120));
            $audience = $channel === 'email'
                ? array_values(array_filter($this->customers, fn ($x) => $x['mail_consent']))
                : array_values(array_filter($this->customers, fn ($x) => $x['sms_consent']));
            $target = min(count($audience), mt_rand(180, 340));

            $cid = DB::table('marketing_campaigns')->insertGetId([
                'tenant_id' => $this->tenantId,
                'list_id' => $channel === 'whatsapp' ? $listId : null,
                'segment_id' => $channel === 'email' ? $this->pick($segIds) : null,
                'name' => $name,
                'channel' => $channel,
                'status' => $status,
                'template_name' => $channel === 'whatsapp' ? 'kampanya_duyuru' : null,
                'template_lang' => 'tr',
                'template_params' => $this->j([]),
                'url_button' => $this->j(['enabled' => true, 'target_url' => 'https://www.lumea-demo.com']),
                'coupon_code' => $this->pick(['CILT20', 'MAKYAJ25', 'SETLER30', 'HOSGELDIN15']),
                'send_window_hours' => 4,
                'cooldown_days' => 3,
                'attribution_days' => 7,
                'recipients_total' => $target,
                'subject' => $subject,
                'body_html' => $subject ? '<h2>'.$subject.'</h2><p>Luméa ile cildinize iyi bakın.</p>' : null,
                'started_at' => $started,
                'completed_at' => $status === 'completed' ? $started->copy()->addHours(6) : null,
                'meta' => $this->j(['demo' => true]),
                'created_at' => $started->copy()->subHours(2),
                'updated_at' => now(),
            ]);
            $campaignIds[] = $cid;

            for ($i = 0; $i < $target; $i++) {
                $cust = $audience[$i % count($audience)];
                $sent = $status !== 'paused' || $i < (int) ($target * 0.4);
                $converted = $sent && mt_rand(1, 1000) <= 34;
                $sentAt = $started->copy()->addMinutes($i * 2);

                $recipientRows[] = [
                    'tenant_id' => $this->tenantId,
                    'campaign_id' => $cid,
                    'phone' => $cust['phone'],
                    'email' => $cust['email'],
                    'name' => $cust['name'].' '.$cust['surname'],
                    'customer_id' => $cust['id'],
                    'status' => $sent ? ($converted ? 'converted' : 'sent') : 'queued',
                    'tracking_token' => bin2hex(random_bytes(8)),
                    'sent_at' => $sent ? $sentAt : null,
                    'converted_at' => $converted ? $sentAt->copy()->addHours(mt_rand(1, 40)) : null,
                    'revenue' => $converted ? round(mt_rand(45000, 260000) / 100, 2) : 0,
                    'coupon_used' => $converted && mt_rand(1, 100) <= 68,
                    'meta' => $this->j([]),
                    'created_at' => $started,
                    'updated_at' => now(),
                ];
            }
        }
        $this->insertChunked('marketing_campaign_recipients', $recipientRows, 800);

        // Otomasyon yolculukları (sipariş bildirimi + sepet kurtarma)
        $journeyRows = [];
        $messageRows = [];
        $custPhones = [];
        foreach ($this->customers as $c) {
            $custPhones[$c['id']] = $c['phone'];
        }
        foreach ($this->orders as $o) {
            if (mt_rand(1, 100) > 62) {
                continue;
            }
            $entered = Carbon::parse($o['order_date']);
            $status = $this->pick(['exhausted', 'exhausted', 'exhausted', 'active', 'stopped', 'converted']);
            $journeyRows[] = [
                'tenant_id' => $this->tenantId,
                'automation' => 'order_status',
                'order_id' => $o['id'],
                'customer_id' => $o['customer_id'],
                'recipient_email' => $o['customer_email'],
                'recipient_phone' => $custPhones[$o['customer_id']] ?? null,
                'status' => $status,
                'current_step' => mt_rand(1, 4),
                'entered_at' => $entered,
                'next_action_at' => $status === 'active' ? $entered->copy()->addDays(2) : null,
                'last_sent_at' => $entered->copy()->addHours(2),
                'converted_at' => $status === 'converted' ? $entered->copy()->addDays(mt_rand(2, 20)) : null,
                'revenue' => $status === 'converted' ? round(mt_rand(40000, 200000) / 100, 2) : 0,
                'meta' => $this->j(['demo' => true]),
                'created_at' => $entered,
                'updated_at' => now(),
            ];
        }

        // Sepet kurtarma yolculukları (siparişe bağlı değil)
        for ($i = 0; $i < 260; $i++) {
            $cust = $this->pick($this->customers);
            $entered = $this->ts($this->skewedDaysAgo(60));
            $status = $this->pick(['converted', 'exhausted', 'exhausted', 'active', 'stopped']);
            $journeyRows[] = [
                'tenant_id' => $this->tenantId,
                'automation' => 'cart_recovery',
                'order_id' => null,
                'customer_id' => $cust['id'],
                'recipient_email' => $cust['email'],
                'recipient_phone' => $cust['phone'],
                'status' => $status,
                'current_step' => mt_rand(1, 3),
                'entered_at' => $entered,
                'next_action_at' => $status === 'active' ? $entered->copy()->addHours(24) : null,
                'last_sent_at' => $entered->copy()->addHours(4),
                'converted_at' => $status === 'converted' ? $entered->copy()->addHours(mt_rand(6, 72)) : null,
                'revenue' => $status === 'converted' ? round(mt_rand(35000, 190000) / 100, 2) : 0,
                'meta' => $this->j(['demo' => true, 'cart_total' => mt_rand(400, 3200)]),
                'created_at' => $entered,
                'updated_at' => now(),
            ];
        }
        $this->insertChunked('marketing_journeys', $journeyRows, 800);

        // Tekil gönderim kayıtları (analitik ekranları için)
        foreach (array_slice($journeyRows, 0, 900) as $j) {
            $isEmail = mt_rand(1, 100) <= 62;
            $sentAt = Carbon::parse($j['last_sent_at']);
            $opened = mt_rand(1, 100) <= ($isEmail ? 41 : 78);
            $clicked = $opened && mt_rand(1, 100) <= 26;
            $converted = $j['status'] === 'converted';

            $messageRows[] = [
                'tenant_id' => $this->tenantId,
                'customer_id' => $j['customer_id'],
                'channel' => $isEmail ? 'email' : 'whatsapp',
                'recipient' => $isEmail
                    ? ($j['recipient_email'] ?? $j['recipient_phone'])
                    : ($j['recipient_phone'] ?? $j['recipient_email']),
                'source' => $j['automation'],
                'source_type' => 'automation',
                'template_id' => $j['automation'].'_step'.$j['current_step'],
                'subject' => $isEmail ? ($j['automation'] === 'cart_recovery' ? 'Sepetinizde unuttuğunuz ürünler var 🛍️' : 'Siparişiniz yolda 📦') : null,
                'body' => $j['automation'] === 'cart_recovery'
                    ? 'Beğendiğiniz ürünler sizi bekliyor. Stoklar tükenmeden tamamlayın.'
                    : 'Siparişiniz hazırlanıyor, kargoya verildiğinde sizi bilgilendireceğiz.',
                'status' => 'sent',
                'provider' => $isEmail ? 'smtp' : 'meta',
                'provider_message_id' => 'demo-'.bin2hex(random_bytes(6)),
                'cost' => $isEmail ? 0 : 0.28,
                'revenue' => $converted ? $j['revenue'] : 0,
                'meta' => $this->j([]),
                'sent_at' => $sentAt,
                'delivered_at' => $sentAt->copy()->addSeconds(mt_rand(2, 40)),
                'opened_at' => $opened ? $sentAt->copy()->addMinutes(mt_rand(2, 600)) : null,
                'clicked_at' => $clicked ? $sentAt->copy()->addMinutes(mt_rand(5, 700)) : null,
                'converted_at' => $converted ? $j['converted_at'] : null,
                'tracking_token' => bin2hex(random_bytes(8)),
                'created_at' => $sentAt,
                'updated_at' => now(),
            ];
        }
        $this->insertChunked('marketing_messages', $messageRows, 800);

        // İYS kuyruğu
        $iysRows = [];
        foreach (array_slice($this->customers, 0, 260) as $c) {
            $status = $this->pick(['sent', 'sent', 'sent', 'pending', 'failed']);
            $iysRows[] = [
                'tenant_id' => $this->tenantId,
                'phone' => $c['phone'],
                'email' => $c['email'],
                'name' => $c['name'].' '.$c['surname'],
                'first_order_at' => $c['created_at'],
                'order_source' => 'Web',
                'status' => $status,
                'types_result' => $this->j($status === 'sent' ? ['MESAJ' => 'ONAY', 'EPOSTA' => 'ONAY'] : []),
                'attempts' => $status === 'failed' ? 3 : ($status === 'sent' ? 1 : 0),
                'last_attempt_at' => $status === 'pending' ? null : now()->subDays(mt_rand(1, 30)),
                'sent_at' => $status === 'sent' ? now()->subDays(mt_rand(1, 30)) : null,
                'last_error' => $status === 'failed' ? 'Netgsm: geçersiz numara formatı' : null,
                'created_at' => now()->subDays(mt_rand(30, 120)),
                'updated_at' => now(),
            ];
        }
        $this->insertChunked('iys_contacts', $iysRows);

        return sprintf('%d segment, %d kampanya, %d alıcı, %d yolculuk, %d gönderim',
            count($segIds), count($campaignIds), count($recipientRows), count($journeyRows), count($messageRows));
    }

    // ── 13. site chatbot ───────────────────────────────────────────────────

    private function seedChat(): string
    {
        $sessionRows = [];
        $plan = [];
        for ($i = 0; $i < 120; $i++) {
            $dialog = $this->content['chats'][$i % count($this->content['chats'])];
            $cust = mt_rand(1, 100) <= 42 ? $this->pick($this->customers) : null;
            $when = $this->ts($this->skewedDaysAgo(45));
            $userTurns = count(array_filter($dialog, fn ($t) => $t[0] === 'u'));
            $handed = mt_rand(1, 100) <= 14;

            $sessionRows[] = [
                'tenant_id' => $this->tenantId,
                'session_key' => 'demo-'.bin2hex(random_bytes(10)),
                'visitor_id' => 'v-'.bin2hex(random_bytes(6)),
                'status' => $handed ? 'handover' : 'closed',
                'customer_id' => $cust['id'] ?? null,
                'contact' => $this->j($cust ? [
                    'name' => $cust['name'].' '.$cust['surname'],
                    'phone' => $cust['phone'],
                    'email' => $cust['email'],
                ] : []),
                'context' => $this->j(['demo' => true]),
                'page_url' => $this->pick([
                    'https://www.lumea-demo.com/',
                    'https://www.lumea-demo.com/kategori/cilt-bakimi-serum',
                    'https://www.lumea-demo.com/sepet',
                    'https://www.lumea-demo.com/urun/lumea-c-aydinlatici-vitamin-c-serumu-30-ml-5001',
                ]),
                'user_message_count' => $userTurns,
                'last_activity_at' => $when->copy()->addMinutes(mt_rand(1, 12)),
                'last_seen_at' => $when->copy()->addMinutes(mt_rand(1, 12)),
                'is_test' => false,
                'created_at' => $when,
                'updated_at' => $when,
            ];
            $plan[] = [$dialog, $when];
        }
        $this->insertChunked('chat_sessions', $sessionRows);

        $sessionIds = DB::table('chat_sessions')->where('tenant_id', $this->tenantId)->orderBy('id')->pluck('id')->all();

        $msgRows = [];
        foreach ($plan as $idx => [$dialog, $when]) {
            $sid = $sessionIds[$idx] ?? null;
            if (! $sid) {
                continue;
            }
            $at = $when->copy();
            foreach ($dialog as $turn) {
                $at = $at->copy()->addSeconds(mt_rand(6, 90));
                $isBot = $turn[0] === 'a';
                $payload = null;

                if ($isBot && mt_rand(1, 100) <= 38) {
                    $recs = [];
                    for ($k = 0; $k < 3; $k++) {
                        $p = $this->pick($this->products);
                        $imgs = json_decode($p['images'], true);
                        $recs[] = [
                            'id' => $p['id'],
                            'name' => $p['name'],
                            'brand' => 'Luméa',
                            'category' => $p['category'],
                            'url' => 'https://www.lumea-demo.com'.$p['url'],
                            'image' => $imgs[0] ?? null,
                            'images' => $imgs,
                            'price' => (string) $p['price'],
                            'discounted_price' => (string) $p['discounted_price'],
                            'in_stock' => $p['stock_quantity'] > 0,
                            'badge' => $p['stock_quantity'] > 0 ? 'İNDİRİMDE' : null,
                        ];
                    }
                    $payload = $this->j([
                        'topic' => 'urun',
                        'provider' => 'anthropic',
                        'confidence' => mt_rand(70, 98) / 100,
                        'products' => $recs,
                    ]);
                }

                $msgRows[] = [
                    'tenant_id' => $this->tenantId,
                    'chat_session_id' => $sid,
                    'role' => $isBot ? 'assistant' : 'user',
                    'content' => $this->fill($turn[1], [
                        'siparis' => 'LM'.mt_rand(100001, 101400),
                        'kargo' => $this->pick($this->people['cargo']),
                        'takip' => (string) mt_rand(100000000000, 999999999999),
                    ]),
                    'payload' => $payload,
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
            }
        }
        $this->insertChunked('chat_messages', $msgRows, 800);

        $visitorRows = [];
        for ($i = 0; $i < 900; $i++) {
            $visitorRows[] = [
                'tenant_id' => $this->tenantId,
                'visitor_id' => 'v-'.bin2hex(random_bytes(8)),
                'created_at' => $this->ts($this->skewedDaysAgo(30)),
            ];
        }
        $this->insertChunked('chat_rollout_visitors', $visitorRows, 800);

        // Chat'te gösterilen ürün kartlarına tıklama/sipariş atfı:
        // panel bu ölçümü search_events'te session_key eşleşmesiyle kurar.
        $shown = DB::table('chat_messages')
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('payload')
            ->get(['chat_session_id', 'payload', 'created_at']);
        $sessionKeys = DB::table('chat_sessions')->where('tenant_id', $this->tenantId)
            ->pluck('session_key', 'id')->all();

        $clickRows = [];
        foreach ($shown as $row) {
            $payload = json_decode($row->payload, true);
            $key = $sessionKeys[$row->chat_session_id] ?? null;
            if (! $key || empty($payload['products'])) {
                continue;
            }
            foreach ($payload['products'] as $pos => $prod) {
                if (mt_rand(1, 100) > 22) {
                    continue;
                }
                $at = Carbon::parse($row->created_at)->addSeconds(mt_rand(5, 180));
                $bought = mt_rand(1, 100) <= 18;
                $clickRows[] = [
                    'tenant_id' => $this->tenantId,
                    'search_query_id' => null,
                    'product_id' => $prod['id'],
                    'platform_product_id' => null,
                    'type' => 'click',
                    'position' => $pos + 1,
                    'session_id' => $key,
                    'is_test' => false,
                    'value' => null,
                    'platform_order_id' => null,
                    'created_at' => $at,
                    'updated_at' => $at,
                ];
                if ($bought) {
                    $clickRows[] = [
                        'tenant_id' => $this->tenantId,
                        'search_query_id' => null,
                        'product_id' => $prod['id'],
                        'platform_product_id' => null,
                        'type' => 'conversion',
                        'position' => $pos + 1,
                        'session_id' => $key,
                        'is_test' => false,
                        'value' => (float) str_replace(',', '.', (string) ($prod['discounted_price'] ?? 0)),
                        'platform_order_id' => (string) mt_rand(100001, 101400),
                        'created_at' => $at->copy()->addMinutes(mt_rand(2, 45)),
                        'updated_at' => $at,
                    ];
                }
            }
        }
        $this->insertChunked('search_events', $clickRows, 500);

        return sprintf('%d oturum, %d chat mesajı, %d ürün etkileşimi',
            count($sessionRows), count($msgRows), count($clickRows));
    }

    // ── 14. site içi arama ─────────────────────────────────────────────────

    private function seedSearch(): string
    {
        $queryRows = [];
        $plan = [];

        foreach ($this->content['searches'] as [$q, $weight, $intent]) {
            for ($i = 0; $i < $weight; $i++) {
                $when = $this->ts($this->skewedDaysAgo(45));
                $results = mt_rand(3, 28);
                $queryRows[] = [
                    'tenant_id' => $this->tenantId,
                    'channel_id' => $this->ch['ticimax-ecommerce'],
                    'customer_id' => mt_rand(1, 100) <= 35 ? $this->pick($this->customers)['id'] : null,
                    'session_id' => 's-'.bin2hex(random_bytes(6)),
                    'raw_query' => $q,
                    'normalized_query' => $q,
                    'parsed_facets' => $this->j([]),
                    'intent' => $intent === 'need' ? 'category' : ($intent === 'ingredient' ? 'facet' : ($intent === 'brand' ? 'brand' : 'product')),
                    'results_count' => $results,
                    'has_results' => true,
                    'source' => mt_rand(1, 100) <= 88 ? 'search' : 'chat',
                    'device' => $this->pick(['mobile', 'mobile', 'mobile', 'desktop', 'tablet']),
                    'ip_address' => '88.'.mt_rand(10, 250).'.'.mt_rand(1, 250).'.'.mt_rand(1, 250),
                    'is_test' => false,
                    'created_at' => $when,
                    'updated_at' => $when,
                ];
                $plan[] = [$when, $results];
            }
        }

        foreach ($this->content['searches_zero'] as $q) {
            for ($i = 0; $i < mt_rand(2, 7); $i++) {
                $when = $this->ts($this->skewedDaysAgo(45));
                $queryRows[] = [
                    'tenant_id' => $this->tenantId,
                    'channel_id' => $this->ch['ticimax-ecommerce'],
                    'customer_id' => null,
                    'session_id' => 's-'.bin2hex(random_bytes(6)),
                    'raw_query' => $q,
                    'normalized_query' => $q,
                    'parsed_facets' => $this->j([]),
                    'intent' => 'product',
                    'results_count' => 0,
                    'has_results' => false,
                    'source' => 'search',
                    'device' => $this->pick(['mobile', 'desktop']),
                    'ip_address' => '88.'.mt_rand(10, 250).'.'.mt_rand(1, 250).'.'.mt_rand(1, 250),
                    'is_test' => false,
                    'created_at' => $when,
                    'updated_at' => $when,
                ];
                $plan[] = [$when, 0];
            }
        }

        $this->insertChunked('search_queries', $queryRows, 800);

        $qIds = DB::table('search_queries')->where('tenant_id', $this->tenantId)->orderBy('id')->pluck('id')->all();

        $eventRows = [];
        foreach ($plan as $idx => [$when, $results]) {
            $qid = $qIds[$idx] ?? null;
            if (! $qid || $results === 0) {
                continue;
            }
            $clicks = mt_rand(1, 100) <= 46 ? mt_rand(1, 2) : 0;
            for ($c = 0; $c < $clicks; $c++) {
                $p = $this->pick($this->products);
                $eventRows[] = [
                    'tenant_id' => $this->tenantId,
                    'search_query_id' => $qid,
                    'product_id' => $p['id'],
                    'platform_product_id' => $p['platform_product_id'],
                    'type' => 'click',
                    'position' => $c + 1,
                    'session_id' => 's-'.bin2hex(random_bytes(6)),
                    'is_test' => false,
                    'value' => null,
                    'platform_order_id' => null,
                    'created_at' => $when->copy()->addSeconds(mt_rand(3, 90)),
                    'updated_at' => $when,
                ];
                if (mt_rand(1, 100) <= 7) {
                    $eventRows[] = [
                        'tenant_id' => $this->tenantId,
                        'search_query_id' => $qid,
                        'product_id' => $p['id'],
                        'platform_product_id' => $p['platform_product_id'],
                        'type' => 'conversion',
                        'position' => $c + 1,
                        'session_id' => 's-'.bin2hex(random_bytes(6)),
                        'is_test' => false,
                        'value' => $p['discounted_price'],
                        'platform_order_id' => (string) mt_rand(100001, 101400),
                        'created_at' => $when->copy()->addMinutes(mt_rand(2, 40)),
                        'updated_at' => $when,
                    ];
                }
            }
        }
        $this->insertChunked('search_events', $eventRows, 800);

        $synonyms = [
            ['c vitamini', ['vitamin c', 'vitamin-c', 'askorbik asit'], 'manual', 'active'],
            ['nemlendirici', ['moisturizer', 'nem kremi', 'nemlendirici krem'], 'manual', 'active'],
            ['güneş kremi', ['spf', 'güneş koruyucu', 'sunscreen'], 'manual', 'active'],
            ['fondöten', ['fondoten', 'foundation', 'fondötem'], 'ai', 'suggested'],
            ['serum', ['seurm', 'srum'], 'ai', 'suggested'],
            ['maskara', ['maskra', 'mascara'], 'ai', 'suggested'],
            ['hyaluronik', ['hiyalüronik', 'hyaluronic', 'ha'], 'ai', 'active'],
            ['bb krem', ['bb cream', 'renkli nemlendirici'], 'ai', 'suggested'],
        ];
        $synRows = [];
        foreach ($synonyms as [$term, $syns, $src, $status]) {
            $synRows[] = [
                'tenant_id' => $this->tenantId,
                'term' => $term,
                'synonyms' => $this->j($syns),
                'type' => 'spelling',
                'source' => $src,
                'status' => $status,
                'hits' => mt_rand(3, 140),
                'meta' => $this->j(['demo' => true]),
                'created_at' => now()->subDays(mt_rand(5, 90)),
                'updated_at' => now(),
            ];
        }
        DB::table('search_synonyms')->insert($synRows);

        return sprintf('%d arama, %d etkileşim, %d eş anlamlı', count($queryRows), count($eventRows), count($synRows));
    }

    // ── 15. ürün önerisi motoru ────────────────────────────────────────────

    private function seedRecommendations(): string
    {
        // Birlikte alınanları gerçek siparişlerden hesapla
        $pairs = [];
        $byOrder = [];
        foreach (DB::table('order_items')->where('tenant_id', $this->tenantId)
            ->whereNotNull('product_id')->get(['order_id', 'product_id', 'created_at']) as $it) {
            $byOrder[$it->order_id][] = [$it->product_id, $it->created_at];
        }
        foreach ($byOrder as $items) {
            for ($i = 0; $i < count($items); $i++) {
                for ($k = 0; $k < count($items); $k++) {
                    if ($i === $k) {
                        continue;
                    }
                    $key = $items[$i][0].':'.$items[$k][0];
                    if (! isset($pairs[$key])) {
                        $pairs[$key] = ['a' => $items[$i][0], 'b' => $items[$k][0], 'n' => 0, 'last' => $items[$i][1]];
                    }
                    $pairs[$key]['n']++;
                    if ($items[$i][1] > $pairs[$key]['last']) {
                        $pairs[$key]['last'] = $items[$i][1];
                    }
                }
            }
        }

        $coRows = [];
        foreach ($pairs as $p) {
            $coRows[] = [
                'tenant_id' => $this->tenantId,
                'product_id' => $p['a'],
                'rec_product_id' => $p['b'],
                'together_count' => $p['n'],
                'last_together_at' => $p['last'],
                'built_at' => now(),
            ];
        }
        $this->insertChunked('rec_copurchases', $coRows, 800);

        // Vitrin etkileşimleri
        $eventRows = [];
        for ($i = 0; $i < 1400; $i++) {
            $surface = $this->pick(['atc', 'atc', 'checkout']);
            $src = $this->pick($this->products);
            $rec = $this->pick($this->products);
            $when = $this->ts($this->skewedDaysAgo(40));
            $session = 's-'.bin2hex(random_bytes(6));

            $eventRows[] = [
                'tenant_id' => $this->tenantId, 'type' => 'impression', 'surface' => $surface,
                'source_product_id' => $src['id'], 'product_id' => $rec['id'],
                'platform_product_id' => $rec['platform_product_id'],
                'position' => mt_rand(1, 4), 'session_id' => $session, 'is_test' => false,
                'value' => null, 'platform_order_id' => null,
                'created_at' => $when, 'updated_at' => $when,
            ];

            if (mt_rand(1, 100) <= 24) {
                $eventRows[] = [
                    'tenant_id' => $this->tenantId, 'type' => 'click', 'surface' => $surface,
                    'source_product_id' => $src['id'], 'product_id' => $rec['id'],
                    'platform_product_id' => $rec['platform_product_id'],
                    'position' => mt_rand(1, 4), 'session_id' => $session, 'is_test' => false,
                    'value' => null, 'platform_order_id' => null,
                    'created_at' => $when->copy()->addSeconds(mt_rand(3, 60)), 'updated_at' => $when,
                ];
            }

            if (mt_rand(1, 100) <= 11) {
                $eventRows[] = [
                    'tenant_id' => $this->tenantId, 'type' => 'atc', 'surface' => $surface,
                    'source_product_id' => $src['id'], 'product_id' => $rec['id'],
                    'platform_product_id' => $rec['platform_product_id'],
                    'position' => mt_rand(1, 4), 'session_id' => $session, 'is_test' => false,
                    'value' => $rec['discounted_price'], 'platform_order_id' => null,
                    'created_at' => $when->copy()->addSeconds(mt_rand(5, 120)), 'updated_at' => $when,
                ];
                if (mt_rand(1, 100) <= 34) {
                    $eventRows[] = [
                        'tenant_id' => $this->tenantId, 'type' => 'conversion', 'surface' => $surface,
                        'source_product_id' => $src['id'], 'product_id' => $rec['id'],
                        'platform_product_id' => $rec['platform_product_id'],
                        'position' => null, 'session_id' => $session, 'is_test' => false,
                        'value' => $rec['discounted_price'],
                        'platform_order_id' => (string) mt_rand(100001, 101400),
                        'created_at' => $when->copy()->addMinutes(mt_rand(2, 30)), 'updated_at' => $when,
                    ];
                }
            }
        }
        $this->insertChunked('rec_events', $eventRows, 800);

        return sprintf('%d birlikte-alım çifti, %d vitrin olayı', count($coRows), count($eventRows));
    }

    // ── 16. sosyal medya ───────────────────────────────────────────────────

    private function seedSocial(): string
    {
        $planId = DB::table('social_plans')->insertGetId([
            'tenant_id' => $this->tenantId,
            'title' => 'Eylül İçerik Planı',
            'period' => 'monthly',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'ends_at' => now()->endOfMonth()->toDateString(),
            'status' => 'active',
            'config' => $this->j([
                'weekly_posts' => 5,
                'channels' => ['instagram', 'facebook'],
                'tone' => 'sıcak, bilgilendirici, uzman',
                'pillars' => ['Ürün tanıtımı', 'Cilt bakım eğitimi', 'Müşteri deneyimi', 'Kampanya'],
            ]),
            'stats' => $this->j(['planned' => 20, 'published' => 12, 'scheduled' => 5, 'draft' => 3]),
            'created_at' => now()->subDays(20),
            'updated_at' => now(),
        ]);

        $assetRows = [];
        foreach ($this->products as $n => $p) {
            if ($n % 2 !== 0) {
                continue;
            }
            $imgs = json_decode($p['images'], true);
            $assetRows[] = [
                'tenant_id' => $this->tenantId,
                'source' => 'drive',
                'drive_file_id' => 'demo-'.$p['id'],
                'folder' => 'Luméa / Ürün Görselleri',
                'name' => $p['sku'].'-01.jpg',
                'mime' => 'image/jpeg',
                'checksum' => md5((string) $p['id']),
                'public_url' => $imgs[0] ?? null,
                'thumb_url' => $imgs[0] ?? null,
                'width' => 626, 'height' => 626,
                'status' => 'ready',
                'score' => round(mt_rand(55, 96) / 10, 1),
                'analysis' => $this->j([
                    'konu' => $p['category'],
                    'kompozisyon' => $this->pick(['flat lay', 'ürün odaklı', 'yaşam tarzı']),
                    'renk_paleti' => $this->pick(['nötr', 'sıcak', 'pastel']),
                ]),
                'used_count' => mt_rand(0, 5),
                'created_at' => now()->subDays(mt_rand(5, 60)),
                'updated_at' => now(),
            ];
        }
        $this->insertChunked('social_assets', $assetRows);

        $postRows = [];
        foreach ($this->content['social'] as $n => [$caption, $channels, $status]) {
            $p = $this->products[($n * 5) % count($this->products)];
            $imgs = json_decode($p['images'], true);
            $published = $status === 'published';
            $when = $published ? now()->subDays(mt_rand(2, 26)) : now()->addDays(mt_rand(1, 12));

            $postRows[] = [
                'tenant_id' => $this->tenantId,
                'plan_id' => $planId,
                'type' => mt_rand(1, 100) <= 60 ? 'image' : 'reel',
                'channels' => $this->j($channels),
                'scheduled_at' => $when,
                'status' => $status,
                'caption' => $caption,
                'media' => $this->j([['type' => 'image', 'url' => $imgs[0] ?? null]]),
                'source' => $this->j(['product_id' => $p['id'], 'product_name' => $p['name']]),
                'publish_results' => $this->j($published
                    ? ['instagram' => ['ok' => true, 'id' => 'demo-'.bin2hex(random_bytes(4)), 'reach' => mt_rand(1800, 24000), 'likes' => mt_rand(60, 1400)]]
                    : []),
                'approved_at' => in_array($status, ['published', 'scheduled'], true) ? $when->copy()->subDays(1) : null,
                'published_at' => $published ? $when : null,
                'created_at' => now()->subDays(mt_rand(3, 30)),
                'updated_at' => now(),
            ];
        }
        $this->insertChunked('social_posts', $postRows);

        return sprintf('1 plan, %d gönderi, %d görsel', count($postRows), count($assetRows));
    }

    // ── 17. reklam stratejileri & öneriler ────────────────────────────────

    private function seedAds(): string
    {
        foreach ($this->content['strategies'] as $s) {
            [$key, $name, $category, $status, $summary, $rationale] = $s;
            DB::table('meta_strategies')->insert([
                'tenant_id' => $this->tenantId,
                'key' => $key,
                'name' => $name,
                'category' => $category,
                'status' => $status,
                'summary' => $summary,
                'rationale' => $rationale,
                'config' => $this->j([
                    'daily_budget' => mt_rand(500, 2500),
                    'objective' => 'OUTCOME_SALES',
                    'optimization' => 'OFFSITE_CONVERSIONS',
                    'attribution' => '7d_click_1d_view',
                ]),
                'steps' => $this->j($this->strategySteps($status)),
                'current_step' => $status === 'active' ? 3 : ($status === 'paused' ? 2 : 1),
                'activated_at' => $status === 'active' ? now()->subDays(mt_rand(6, 40)) : null,
                'created_at' => now()->subDays(mt_rand(20, 90)),
                'updated_at' => now(),
            ]);
        }

        $recRows = [];
        foreach ($this->content['ad_recommendations'] as $n => $r) {
            [$rule, $severity, $title, $reason, $action] = $r;
            foreach (['campaign', 'adset', 'ad'] as $li => $level) {
                if ($li > ($n % 2)) {
                    continue;
                }
                $recRows[] = [
                    'tenant_id' => $this->tenantId,
                    'ad_account_id' => 'act_demo1234567',
                    'level' => $level,
                    'object_id' => (string) (23850000000000000 + $n * 137 + $li),
                    'object_name' => $this->pick([
                        'Luméa — Katalog Satış', 'Luméa — Sepet Terk RT', 'Luméa — Yeni Müşteri',
                        'Cilt Bakımı Setleri', 'Kış Bakımı Kreatif A', 'UGC Video 03',
                    ]),
                    'rule' => $rule,
                    'severity' => $severity,
                    'title' => $title,
                    'reason' => $reason,
                    'action' => $action,
                    'action_params' => json_encode(['amount_pct' => 20], JSON_UNESCAPED_UNICODE),
                    'metrics' => json_encode([
                        'spend' => mt_rand(80000, 480000) / 100,
                        'roas' => mt_rand(90, 620) / 100,
                        'cpa' => mt_rand(4000, 34000) / 100,
                        'ctr' => mt_rand(60, 380) / 100,
                        'frequency' => mt_rand(120, 460) / 100,
                    ], JSON_UNESCAPED_UNICODE),
                    'status' => $this->pick(['pending', 'pending', 'pending', 'applied', 'dismissed']),
                    'dedupe_key' => 'demo:'.$rule.':'.$level.':'.$n,
                    'created_at' => now()->subDays(mt_rand(0, 6)),
                    'updated_at' => now(),
                ];
            }
        }
        DB::table('meta_ad_recommendations')->insert($recRows);

        return sprintf('%d strateji, %d reklam önerisi', count($this->content['strategies']), count($recRows));
    }


    /** Meta strateji adımları — panelin beklediği şema (no/status/type/...). */
    private function strategySteps(string $status): array
    {
        $done = match ($status) {
            'active' => 2,
            'paused' => 1,
            default => 0,
        };

        $defs = [
            ['Kitle ve katalog hazırlığı', 'analysis', 'Son 30 günün satış verisinden hedef kitle ve katalog seti çıkarılır.', true],
            ['Kreatif üretimi (3 varyant)', 'creative', 'Ürün hero görseli + 2 UGC formatı üretilir, onaya sunulur.', false],
            ['Kampanya kurulumu', 'campaign', 'Kampanya, reklam seti ve reklamlar Meta tarafında oluşturulur.', false],
            ['7 günlük karne ve optimizasyon', 'report', 'İlk hafta sonunda ROAS/CPA karnesi çıkarılır, ölçekleme kararı verilir.', true],
        ];

        $steps = [];
        foreach ($defs as $i => [$title, $type, $desc, $needsApproval]) {
            $isDone = $i < $done;
            $steps[] = [
                'no' => $i + 1,
                'title' => $title,
                'type' => $type,
                'desc' => $desc,
                'executor' => null,
                'needs_approval' => $needsApproval,
                'auto' => $i === 0,
                'status' => $isDone ? 'completed' : ($i === $done && $status === 'active' ? 'waiting_approval' : 'pending'),
                'result' => null,
                'completed_at' => $isDone ? now()->subDays(mt_rand(3, 20))->toDateTimeString() : null,
            ];
        }

        return $steps;
    }

    // ── 18. ürün feed'i ────────────────────────────────────────────────────


    private function seedFeed(): string
    {
        $profileId = DB::table('feed_profiles')->insertGetId([
            'tenant_id' => $this->tenantId,
            'name' => 'Meta Katalog Feed',
            'slug' => 'meta-katalog',
            'template' => 'meta_catalog',
            'output_format' => 'atom',
            'xml_root_tag' => 'feed',
            'xml_item_tag' => 'entry',
            'xml_namespace' => 'http://base.google.com/ns/1.0',
            'xml_namespace_prefix' => 'g',
            'variant_mode' => 'variant',
            'currency' => 'TRY',
            'storefront_base_url' => 'https://www.lumea-demo.com',
            'exclude_out_of_stock' => false,
            'exclude_inactive' => true,
            'is_active' => true,
            'settings' => $this->j(['demo' => true]),
            'last_generated_at' => now()->subHours(3),
            'product_count' => count($this->products),
            'feed_token' => bin2hex(random_bytes(16)),
            'created_at' => now()->subDays(60),
            'updated_at' => now(),
        ]);

        $rows = [];
        foreach ($this->products as $idx => $p) {
            $def = $this->catalog[$idx];
            $benefit = $def['specs']['Ana Etken'] ?? $def['cat'];
            $rows[] = [
                'tenant_id' => $this->tenantId,
                'product_id' => $p['id'],
                'feed_profile_id' => $profileId,
                'title' => mb_substr($p['name'].' — '.$benefit, 0, 150),
                'description' => mb_substr($def['desc'].' Luméa kalitesiyle, dermatolojik test edilmiş formül.', 0, 480),
                'extra_fields' => $this->j([
                    'custom_label_0' => $p['performance_label'],
                    'custom_label_1' => $p['stock_quantity'] > 0 ? 'STOK/E' : 'STOK/H',
                    'custom_label_2' => explode(' > ', $def['path'])[0],
                ]),
                'prompt_used' => 'Ürün adı ve özelliklerinden Meta kataloğu için 150 karakteri aşmayan fayda odaklı başlık üret.',
                'model_used' => 'claude-sonnet-5',
                'is_excluded' => false,
                'generated_at' => now()->subDays(mt_rand(1, 20)),
                'created_at' => now()->subDays(mt_rand(1, 20)),
                'updated_at' => now(),
            ];
        }
        $this->insertChunked('feed_ai_contents', $rows);

        return sprintf('1 feed profili, %d AI içerik', count($rows));
    }

    // ── 19. bilgi bankası, hazır cevaplar, AI eğitim verisi ───────────────

    private function seedKnowledge(): string
    {
        $kbRows = [];
        foreach ($this->content['knowledge'] as [$cat, $title, $body, $tags]) {
            $kbRows[] = [
                'tenant_id' => $this->tenantId,
                'category' => $cat,
                'title' => $title,
                'content' => $body,
                'tags' => $this->j($tags),
                'is_active' => true,
                'created_by' => $this->agents[count($this->agents) - 1],
                'created_at' => now()->subDays(mt_rand(30, 180)),
                'updated_at' => now(),
            ];
        }
        DB::table('knowledge_base')->insert($kbRows);

        $qrRows = [];
        foreach ($this->content['quick_replies'] as $n => [$cat, $title, $body]) {
            $qrRows[] = [
                'tenant_id' => $this->tenantId,
                'category' => $cat,
                'title' => $title,
                'content' => $body,
                'is_active' => true,
                'is_default' => true,
                'sort_order' => $n,
                'usage_count' => mt_rand(3, 240),
                'last_used_at' => now()->subDays(mt_rand(0, 20)),
                'created_by' => $this->agents[count($this->agents) - 1],
                'created_at' => now()->subDays(mt_rand(30, 150)),
                'updated_at' => now(),
            ];
        }
        DB::table('quick_replies')->insert($qrRows);

        // Geçmiş konuşmalardan AI eğitim çifti
        $trainRows = [];
        foreach ($this->content['conversations'] as $script) {
            $q = null;
            foreach ($script['turns'] as $t) {
                if ($t[0] === 'c' && $q === null) {
                    $q = $t[1];
                    continue;
                }
                if ($t[0] === 'a' && $q !== null) {
                    $trainRows[] = [
                        'tenant_id' => $this->tenantId,
                        'source_type' => 'conversation',
                        'channel_type' => $this->pick(['whatsapp', 'instagram-message', 'email']),
                        'question' => $this->fill($q, ['ad' => 'Müşteri', 'siparis' => 'LM100000', 'urun' => 'ürün', 'gun' => '5']),
                        'answer' => $this->fill($t[1], ['ad' => 'Müşteri', 'siparis' => 'LM100000', 'kargo' => 'Sürat Kargo', 'takip' => '123456789012', 'tutar' => '749,90', 'kod' => 'TELAFI15']),
                        'context' => $this->j(['topic' => $script['topic']]),
                        'quality_score' => round(mt_rand(70, 98) / 100, 2),
                        'is_active' => true,
                        'used_count' => mt_rand(0, 40),
                        'created_at' => now()->subDays(mt_rand(10, 120)),
                        'updated_at' => now(),
                    ];
                    $q = null;
                }
            }
        }
        $this->insertChunked('ai_training_data', $trainRows);

        // AI yanıt kayıtları — temsilcinin AI önerisini onaylama/düzenleme geçmişi
        $aiMessages = DB::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.tenant_id', $this->tenantId)
            ->where('m.is_ai_generated', true)
            ->orderBy('m.id')
            ->limit(160)
            ->get(['m.id', 'm.conversation_id', 'm.sender_id', 'm.content', 'm.ai_original_content', 'm.created_at']);

        $logRows = [];
        foreach ($aiMessages as $m) {
            $prompt = DB::table('messages')
                ->where('conversation_id', $m->conversation_id)
                ->where('sender_type', 'customer')
                ->where('id', '<', $m->id)
                ->orderByDesc('id')
                ->value('content');

            $edited = $m->ai_original_content !== null && $m->ai_original_content !== $m->content;

            $logRows[] = [
                'tenant_id' => $this->tenantId,
                'conversation_id' => $m->conversation_id,
                'user_id' => $m->sender_id,
                'prompt' => $prompt ?: 'Müşteri mesajı',
                'ai_response' => $m->ai_original_content ?? $m->content,
                'final_response' => $m->content,
                'was_approved' => ! $edited,
                'was_edited' => $edited,
                'response_time_ms' => mt_rand(680, 4200),
                'model_used' => 'claude-sonnet-5',
                'training_data_ids' => $this->j([]),
                'confidence_score' => round(mt_rand(64, 97) / 100, 2),
                'detected_intent' => $this->j(['intent' => $this->pick(['kargo', 'iade', 'urun_onerisi', 'stok', 'odeme', 'genel'])]),
                'extracted_entities' => $this->j([]),
                'created_at' => $m->created_at,
                'updated_at' => $m->created_at,
            ];
        }
        $this->insertChunked('ai_response_logs', $logRows);

        return sprintf('%d bilgi kartı, %d hazır cevap, %d eğitim çifti, %d AI yanıt kaydı',
            count($kbRows), count($qrRows), count($trainRows), count($logRows));
    }

    // ── 20. favoriler, stok alarmı, setler, kategori önerileri ────────────

    private function seedExtras(): string
    {
        // Favoriler
        $favRows = [];
        for ($i = 0; $i < 420; $i++) {
            $cust = $this->pick($this->customers);
            $p = $this->pick($this->products);
            $imgs = json_decode($p['images'], true);
            $when = $this->ts($this->skewedDaysAgo(120));
            $favRows[] = [
                'tenant_id' => $this->tenantId,
                'channel_id' => $this->ch['ticimax-ecommerce'],
                'customer_id' => $cust['id'],
                'product_id' => $p['id'],
                'platform_favorite_id' => 'demo-fav-'.$i,
                'platform_customer_id' => $cust['platform_customer_id'],
                'platform_product_id' => $p['platform_product_id'],
                'product_name' => $p['name'],
                'product_sku' => $p['sku'],
                'product_image' => $imgs[0] ?? null,
                'product_url' => 'https://www.lumea-demo.com'.$p['url'],
                'product_price' => $p['discounted_price'],
                'product_price_tax' => round((float) $p['discounted_price'] * 1.2, 2),
                'currency' => 'TRY',
                'stock_quantity' => $p['stock_quantity'],
                'variant_count' => count($this->variants[$p['id']] ?? []),
                'favorited_at' => $when,
                'created_at' => $when,
                'updated_at' => $when,
            ];
        }
        $this->insertChunked('product_favorites', $favRows);

        // Gelince haber ver
        $outOfStock = array_values(array_filter($this->products, fn ($p) => $p['stock_quantity'] === 0));
        $alertRows = [];
        if ($outOfStock) {
            for ($i = 0; $i < 46; $i++) {
                $cust = $this->pick($this->customers);
                $p = $this->pick($outOfStock);
                $vars = $this->variants[$p['id']] ?? [];
                $when = $this->ts($this->skewedDaysAgo(40));
                $notified = mt_rand(1, 100) <= 22;
                $alertRows[] = [
                    'tenant_id' => $this->tenantId,
                    'channel_id' => $this->ch['ticimax-ecommerce'],
                    'product_id' => $p['id'],
                    'customer_id' => $cust['id'],
                    'platform_product_id' => $p['platform_product_id'],
                    'variant_label' => $vars ? implode(' / ', array_values(json_decode($this->pick($vars)['attributes'], true))) : null,
                    'name' => $cust['name'].' '.$cust['surname'],
                    'email' => $cust['email'],
                    'phone' => $cust['phone'],
                    'status' => $notified ? 'notified' : 'waiting',
                    'notified_at' => $notified ? $when->copy()->addDays(mt_rand(2, 12)) : null,
                    'created_at' => $when,
                    'updated_at' => $when,
                ];
            }
        }
        $this->insertChunked('stock_alerts', $alertRows);

        // Ürün setleri (Meta katalog segmentleri)
        $sets = [
            ['Çok Satanlar', 'Son 30 günün en çok satan ürünleri', 'TIER/A'],
            ['Cilt Bakımı', 'Tüm cilt bakım ürünleri', null],
            ['İndirimdekiler', 'Aktif indirimi olan ürünler', null],
        ];
        $setCount = 0;
        foreach ($sets as [$name, $desc, $tier]) {
            $sid = DB::table('product_sets')->insertGetId([
                'tenant_id' => $this->tenantId,
                'name' => $name,
                'description' => $desc,
                'is_active' => true,
                'source' => $this->j(['type' => $tier ? 'performance' : 'category', 'value' => $tier ?? 'Cilt Bakımı']),
                'last_synced_at' => now()->subHours(mt_rand(1, 12)),
                'created_at' => now()->subDays(mt_rand(20, 80)),
                'updated_at' => now(),
            ]);
            $members = array_values(array_filter($this->products, function ($p) use ($tier) {
                return $tier ? $p['performance_label'] === $tier : str_contains((string) $p['category_path'], 'Cilt Bakımı');
            }));
            $itemRows = [];
            foreach ($members as $m) {
                $itemRows[] = [
                    'product_set_id' => $sid,
                    'product_id' => $m['id'],
                    'retailer_id' => $m['sku'],
                    'payload' => $this->j(['name' => $m['name']]),
                    'created_at' => now(), 'updated_at' => now(),
                ];
                $setCount++;
            }
            $this->insertChunked('product_set_items', $itemRows);
        }

        // Kategori denetçisi önerileri
        $catIds = DB::table('platform_categories')
            ->where('tenant_id', $this->tenantId)->where('children_count', 0)
            ->pluck('platform_category_id')->all();
        $sugRows = [];
        foreach (array_slice($this->products, 0, 26) as $n => $p) {
            $status = $this->pick(['suggested', 'suggested', 'applied', 'auto_applied', 'rejected']);
            $suggested = $this->pick($catIds);
            $sugRows[] = [
                'tenant_id' => $this->tenantId,
                'product_id' => $p['id'],
                'action' => 'recategorize',
                'current' => $this->j([
                    'category' => $p['category'],
                    'path' => $p['category_path'],
                    'category_ids' => [$suggested],
                ]),
                'suggested_primary_id' => $suggested,
                'suggested_category_ids' => $this->j([$suggested]),
                'confidence' => round(mt_rand(62, 97) / 100, 2),
                'tier' => mt_rand(1, 100) <= 40 ? 'auto' : 'review',
                'sources' => $this->j([
                    'knn' => [
                        'path' => $p['category_path'],
                        'share' => round(mt_rand(45, 92) / 100, 2),
                        'neighbors' => 20,
                        'category_id' => $suggested,
                    ],
                    'llm' => [
                        'confidence' => round(mt_rand(70, 98) / 100, 2),
                        'primary_id' => $suggested,
                        'new_category' => null,
                        'secondary_ids' => [],
                    ],
                    'family' => null,
                    'agree' => ['knn'],
                    'age_group' => null,
                    'gender_conflict' => false,
                ]),
                'rationale' => 'Ürün adı, içerik listesi ve görsel birlikte değerlendirildiğinde ürünün '
                    .$p['category'].' kategorisine ait olduğu yüksek güvenle belirlendi; benzer ürünlerin '
                    .'kategori dağılımı da bu eşleşmeyi destekliyor.',
                'status' => $status,
                'applied_at' => in_array($status, ['applied', 'auto_applied'], true) ? now()->subDays(mt_rand(1, 20)) : null,
                'created_at' => now()->subDays(mt_rand(1, 30)),
                'updated_at' => now(),
            ];
        }
        $this->insertChunked('category_suggestions', $sugRows);

        // Kanal istatistikleri (Özet ekranı kartları)
        $statRows = [];
        foreach ($this->ch as $type => $id) {
            $statRows[] = [
                'tenant_id' => $this->tenantId,
                'channel_id' => $id,
                'date' => now()->toDateString(),
                'total_conversations' => mt_rand(6, 90),
                'answered' => mt_rand(4, 80),
                'unanswered' => mt_rand(0, 12),
                'avg_response_time_seconds' => mt_rand(120, 2400),
                'ai_used_count' => mt_rand(0, 40),
                'ai_approved_count' => mt_rand(0, 30),
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        try {
            DB::table('channel_stats')->insert($statRows);
        } catch (\Throwable $e) {
            $statRows = [];
        }

        return sprintf('%d favori, %d stok alarmı, %d set üyesi, %d kategori önerisi',
            count($favRows), count($alertRows), $setCount, count($sugRows));
    }
}
