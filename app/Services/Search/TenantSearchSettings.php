<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Akıllı Arama — tenant'a özel ayarlar (tenants.settings jsonb, panelden yönetilir).
 * config/search.php güvenli VARSAYILAN; tenant ayarı varsa onu ezer.
 *
 * Anahtarlar (tenants.settings): search_require_stock, search_require_variant_stock,
 * search_require_image, search_badge_enabled, search_widget_trigger, search_widget_input,
 * search_widget_template (drawer=yandan panel | fullscreen=tam sayfa, imza.com.tr tarzı).
 */
class TenantSearchSettings
{
    /** @var array<int, array> istek içi cache */
    protected static array $memo = [];

    public static function get(int $tenantId): array
    {
        return static::$memo[$tenantId] ??= Cache::remember(
            "search:tsettings:{$tenantId}",
            300,
            function () use ($tenantId) {
                $raw = DB::table('tenants')->where('id', $tenantId)->value('settings');
                $s = is_string($raw) ? (json_decode($raw, true) ?: []) : ((array) $raw);

                return [
                    'require_stock' => static::boolOr($s['search_require_stock'] ?? null, (bool) config('search.retrieval.require_stock', true)),
                    'require_variant_stock' => static::boolOr($s['search_require_variant_stock'] ?? null, (bool) config('search.retrieval.require_variant_stock', true)),
                    'require_image' => static::boolOr($s['search_require_image'] ?? null, (bool) config('search.retrieval.require_image', true)),
                    'badge_enabled' => static::boolOr($s['search_badge_enabled'] ?? null, true),
                    // Doğrudan arama (stok kodu / ürün adı birebir) stoksuz ürünü de getirir, listenin EN ALTINDA. Kapalı = Tonny.
                    'oos_direct' => static::boolOr($s['search_oos_direct'] ?? null, false),
                    'trigger' => trim((string) ($s['search_widget_trigger'] ?? '')),
                    'input' => trim((string) ($s['search_widget_input'] ?? '')),
                    'template' => in_array($t = trim((string) ($s['search_widget_template'] ?? '')), ['drawer', 'fullscreen'], true) ? $t : 'drawer',
                    // Marka kimliği: yazı tipi + vurgu + metin rengi (boş = widget'ın kendi varsayılanı; Tonny değişmez)
                    'theme' => static::theme($s['search_widget_theme'] ?? null),
                    // Arama kutusunda daktilo gibi yazılan örnek sorgular (virgülle); boşsa mağazanın kendi kategorilerinden türetilir
                    // Malzeme/taş sözlüğü (virgülle): bu kelimeler ürün AÇIKLAMASINDA da aranır (ad eşleşmesi gibi 1.0 puan). Boş = kapalı (Tonny).
                    'material_terms' => array_values(array_unique(array_filter(array_map(fn ($v) => mb_strtolower(trim($v), 'UTF-8'), explode(',', (string) ($s['search_material_terms'] ?? '')))))),
                    'placeholders' => array_values(array_filter(array_map(fn ($v) => mb_substr(trim($v), 0, 40), explode(',', (string) ($s['search_widget_placeholders'] ?? ''))))),
                ];
            }
        );
    }

    /** Ayar değişince çağır (panel kaydet → anında etki). */
    public static function forget(int $tenantId): void
    {
        unset(static::$memo[$tenantId]);
        Cache::forget("search:tsettings:{$tenantId}");
    }

    /** @return array{font: string, accent: string, ink: string, ratio: string} */
    public static function theme($raw): array
    {
        $t = is_string($raw) ? (json_decode($raw, true) ?: []) : ((array) $raw);
        $hex = fn ($v) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v) ? strtolower((string) $v) : '';

        return [
            'font' => mb_substr(preg_replace('/[^A-Za-z0-9 ,\-"\']/', '', (string) ($t['font'] ?? '')), 0, 80),
            'accent' => $hex($t['accent'] ?? ''),
            'ink' => $hex($t['ink'] ?? ''),
            // ürün kartı görsel oranı: 2/3 (Ticimax dikey), 3/4, 1/1 (takı/kare)
            'ratio' => in_array($r = (string) ($t['ratio'] ?? ''), ['2/3', '3/4', '1/1'], true) ? $r : '',
        ];
    }

    protected static function boolOr($v, bool $default): bool
    {
        if ($v === null || $v === '') {
            return $default;
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
