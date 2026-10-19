<?php

namespace App\Services\AppPromo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Uygulamaya Özel" kulakçığı (CRO) — mobil uygulamayı indirene özel indirim kodu.
 * Web'de: kod + QR (telefonla okutunca cihaza göre App Store / Google Play'e gider) + mağaza butonları.
 * Mobil web'de: kod + cihaza göre tek "İndir" butonu (Yunbi /go yönlendirmesi üzerinden, ölçülür).
 * Uygulama içinde (Ticimax UA): yalnız kod + kopyala.
 * Anahtarlar: apppromo_enabled, apppromo_domains, apppromo_theme (JSON), apppromo_rules (JSON).
 */
class TenantAppPromoSettings
{
    public const PRESETS = ['red', 'black', 'gradient', 'royal', 'emerald', 'sunset'];

    public const TAB_POSITIONS = ['right-middle', 'right-bottom', 'left-middle', 'left-bottom'];

    public const FREQUENCIES = ['day', 'session', 'always'];

    public const IN_APP_MODES = ['code', 'hide'];

    public const DEFAULT_THEME = [
        'preset' => 'black',
        'accent' => '#111827',      // kulakçık + CTA
        'accent2' => '#374151',     // gradyanın diğer ucu
        'ink' => '#111827',
        'bg' => '#ffffff',
        'radius' => 20,
        'tab_position' => 'right-middle',
        'tab_text' => 'UYGULAMAYA ÖZEL',
        'tab_badge' => '%10',       // kulakçıkta beyaz hap (boş = gizle)
        'tab_emoji' => '📱',
        'tab_pulse' => true,
        'font' => '',
    ];

    public const DEFAULT_RULES = [
        'code' => 'APP10',                 // Ticimax'ta "yalnız mobil uygulama" kısıtıyla açılmış kupon kodu
        'channel_codes' => [],             // kanal → kod (ör. meta: APP10M, google: APP10G); reklamdan gelen web ziyaretçisine o kod gösterilir → uygulama siparişi kanala bağlanır. Boş kanal = code
        'discount_label' => '%10 İndirim', // büyük vurgu metni
        'eyebrow' => 'UYGULAMAYA ÖZEL',
        'title' => 'Uygulamayı indir, %10 kazan',
        'subtitle' => 'Kodu yalnızca Tonny Black mobil uygulamasında kullanabilirsin.',
        'terms' => '',                     // örn. "Tek kullanımlık · 500 TL ve üzeri · 31 Ekim\'e kadar"
        'valid_until' => null,             // Y-m-d — doluysa panelde/widget'ta "…'e kadar" ve sayaç
        'ios_url' => '',
        'android_url' => '',
        'landing_url' => '/app-indir',     // masaüstünde/bilinmeyen cihazda düşülen mağaza sayfası (boş = ios_url)
        'show_code_web' => true,           // web'de kodu göster (false: "kod uygulamada görünür")
        'show_qr' => true,                 // masaüstünde QR
        'in_app_mode' => 'code',           // code (uygulama içinde yalnız kodu göster) | hide
        'cta_ios' => "App Store'dan İndir",
        'cta_android' => "Google Play'den İndir",
        'copy_text' => 'Kodu Kopyala',
        'auto_open' => true,
        'auto_open_mobile' => false,       // mobilde kendiliğinden alt sayfa açılmasın (minimal)
        'auto_open_delay' => 20,           // sn — sekme görünürken
        'auto_open_scroll' => 50,          // % — 0 kapalı
        'exit_intent' => true,
        'tab_close' => true,               // kulakçıkta × (kapatınca tab_close_days gün gösterilmez)
        'tab_close_days' => 3,
        'frequency' => 'day',              // day | session | always
        'no_auto_page_types' => ['product'],
        'hide_paths' => '/checkout,/sepet,/odeme,/Sepetim,/UyeGiris,/Hesabim,/app-indir',
        'hide_page_types' => [],
        'max_width' => 0,
        'min_width' => 0,
    ];

    protected static array $memo = [];

    public static function get(int $tenantId): array
    {
        return static::$memo[$tenantId] ??= Cache::remember("apppromo:tsettings:{$tenantId}", 300, function () use ($tenantId) {
            $raw = DB::table('tenants')->where('id', $tenantId)->value('settings');
            $s = is_string($raw) ? (json_decode($raw, true) ?: []) : ((array) $raw);
            $theme = static::json($s['apppromo_theme'] ?? null);
            $rules = static::json($s['apppromo_rules'] ?? null);
            $theme = array_merge(self::DEFAULT_THEME, is_array($theme) ? $theme : []);
            $rules = array_merge(self::DEFAULT_RULES, is_array($rules) ? $rules : []);
            if (! in_array($theme['preset'], self::PRESETS, true)) {
                $theme['preset'] = 'black';
            }
            if (! in_array($theme['tab_position'], self::TAB_POSITIONS, true)) {
                $theme['tab_position'] = 'right-middle';
            }
            if (! in_array($rules['frequency'], self::FREQUENCIES, true)) {
                $rules['frequency'] = 'day';
            }
            if (! in_array($rules['in_app_mode'], self::IN_APP_MODES, true)) {
                $rules['in_app_mode'] = 'code';
            }
            $rules['code'] = mb_substr(strtoupper(trim((string) $rules['code'])), 0, 32);
            $rules['channel_codes'] = static::channelCodes($rules['channel_codes'] ?? []);
            $vu = trim((string) ($rules['valid_until'] ?? ''));
            $rules['valid_until'] = ($vu !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $vu) && strtotime($vu) !== false) ? $vu : null;

            return [
                'enabled' => filter_var($s['apppromo_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'domains' => (string) ($s['apppromo_domains'] ?? ''),
                'theme' => $theme,
                'rules' => $rules,
            ];
        });
    }

    public static function forget(int $tenantId): void
    {
        unset(static::$memo[$tenantId]);
        Cache::forget("apppromo:tsettings:{$tenantId}");
    }

    /** ['meta' => 'APP10M', 'google' => 'APP10G'] — yalnız bilinen kanallar, boşlar atılır. */
    public static function channelCodes($v): array
    {
        $v = is_array($v) ? $v : (static::json($v) ?? []);
        $out = [];
        foreach (['meta', 'google'] as $ch) {
            $c = mb_substr(strtoupper(trim((string) ($v[$ch] ?? ''))), 0, 32);
            if ($c !== '') {
                $out[$ch] = $c;
            }
        }

        return $out;
    }

    /** Ana kod + kanal kodları (sipariş/kod kullanımı analitiği için). */
    public static function allCodes(array $rules): array
    {
        return array_values(array_unique(array_filter(array_merge([(string) ($rules['code'] ?? '')], array_values($rules['channel_codes'] ?? [])))));
    }

    protected static function json($v): ?array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, true);

            return is_array($d) ? $d : null;
        }

        return null;
    }
}
