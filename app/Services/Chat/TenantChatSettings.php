<?php

namespace App\Services\Chat;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * AI Chatbot — tenant'a özel ayarlar (tenants.settings jsonb, panelden yönetilir).
 * TenantSearchSettings desenini izler: config güvenli varsayılan, tenant ayarı ezer.
 *
 * Anahtarlar (tenants.settings): chat_widget_enabled, chat_widget_domains,
 * chat_bot_name, chat_greeting, chat_quick_replies (virgüllü), chat_extra_instructions,
 * chat_strict_rules (katı kurallar — prompt'ta İSTİSNASIZ blok), chat_handoff_enabled,
 * chat_theme_color, chat_widget_template (modern|minimal|soft),
 * chat_rollout_mode/chat_rollout_limit (kademeli açılım), chat_answer_mode/
 * chat_min_confidence/chat_allow_* (doğruluk kapıları), chat_hours_enabled/chat_hours.
 */
class TenantChatSettings
{
    /** @var array<int, array> istek içi cache */
    protected static array $memo = [];

    public static function get(int $tenantId): array
    {
        return static::$memo[$tenantId] ??= Cache::remember(
            "chat:tsettings:{$tenantId}",
            300,
            function () use ($tenantId) {
                $raw = DB::table('tenants')->where('id', $tenantId)->value('settings');
                $s = is_string($raw) ? (json_decode($raw, true) ?: []) : ((array) $raw);

                $quick = array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) ($s['chat_quick_replies'] ?? ''))
                )));

                return [
                    'enabled' => filter_var($s['chat_widget_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'bot_name' => trim((string) ($s['chat_bot_name'] ?? '')) ?: 'AI Asistan',
                    'greeting' => trim((string) ($s['chat_greeting'] ?? ''))
                        ?: 'Merhaba! Ben yapay zeka destekli asistanım. Ürün önerisi, sipariş durumu ve sorularınız için buradayım. Size nasıl yardımcı olabilirim?',
                    // Çipler: panelde yazılmadıysa çok sorulan sorular seti
                    // (ChatIntents — her birinin arkasında deterministik cevap var)
                    'quick_replies' => $quick ?: ChatIntents::defaultChips(),
                    // Sipariş durumu → müşteriye ne anlama geldiğini söyleyen cümle
                    'order_notes' => static::orderNotes($s['chat_order_notes'] ?? null),
                    'extra_instructions' => trim((string) ($s['chat_extra_instructions'] ?? '')),
                    'strict_rules' => trim((string) ($s['chat_strict_rules'] ?? '')),
                    'handoff_enabled' => static::boolOr($s['chat_handoff_enabled'] ?? null, true),
                    'theme_color' => trim((string) ($s['chat_theme_color'] ?? '')) ?: '#1f2937',
                    'template' => in_array($t = trim((string) ($s['chat_widget_template'] ?? '')), ['modern', 'minimal', 'soft', 'elegant'], true) ? $t : 'modern',
                    // Ilk mesaj görünümü: panel (karşılama sohbet penceresinde — varsayılan)
                    // | teaser (balonun yanında kapatılabilir kabarcık, tıklayınca sohbet açılır)
                    'greeting_style' => in_array($g = trim((string) ($s['chat_greeting_style'] ?? '')), ['panel', 'teaser'], true) ? $g : 'panel',
                    'teaser_text' => trim((string) ($s['chat_teaser_text'] ?? '')),
                    // Kutucuk (başlatıcı): bubble (yuvarlak balon, sağ alt — varsayılan)
                    // | tab (rue/Pushouse tarzı: sağ kenara yapışık dikey sekme, ekran ortası)
                    'launcher_style' => in_array($l = trim((string) ($s['chat_launcher_style'] ?? '')), ['bubble', 'tab'], true) ? $l : 'bubble',

                    // ── Kademeli açılım (4 Eyl) — chatbot son tüketiciye görünür,
                    // herkese birden açılmaz. Bkz ChatRollout.
                    'rollout_mode' => in_array($r = trim((string) ($s['chat_rollout_mode'] ?? '')), ['off', 'limited', 'all'], true) ? $r : 'limited',
                    'rollout_limit' => max(0, (int) ($s['chat_rollout_limit'] ?? 10)),

                    // ── Doğruluk kapıları (4 Eyl) — emin değilse cevaplama, devret.
                    // answer_mode: strict (yalnız onaylı metne dayanan cevap) | open (LLM serbest)
                    'answer_mode' => in_array($a = trim((string) ($s['chat_answer_mode'] ?? '')), ['strict', 'open'], true) ? $a : 'strict',
                    'min_confidence' => static::floatOr($s['chat_min_confidence'] ?? null, 0.75),
                    'allow_faq' => static::boolOr($s['chat_allow_faq'] ?? null, true),
                    'allow_order_status' => static::boolOr($s['chat_allow_order_status'] ?? null, true),
                    'allow_products' => static::boolOr($s['chat_allow_products'] ?? null, true),
                    // Devir öncesi müşteriden sorusunun detayını iste
                    'ask_detail' => static::boolOr($s['chat_ask_detail'] ?? null, true),

                    // ── Mesai saatleri (4 Eyl) — devirde doğru beklenti verir.
                    'hours_enabled' => static::boolOr($s['chat_hours_enabled'] ?? null, true),
                    'hours' => ChatBusinessHours::sanitize($s['chat_hours'] ?? null),
                ];
            }
        );
    }

    /**
     * Sipariş durum notları: "durum kodu: müşteriye söylenecek cümle".
     *
     * Panelde satır satır girilir ("0: Siparişiniz alındı..."); girilmeyen
     * durumlar için aşağıdaki varsayılan kullanılır. Ticimax durum kodları:
     * 0 alındı · 3 ödeme bekliyor · 4 paketleniyor · 5 tedarik · 6 kargoda ·
     * 7 teslim · 8 iptal · 9/11/12/13/16/17 iade akışı · 18 teslim edilemedi ·
     * 19/20/21 mağaza teslim akışı.
     *
     * @return array<int, string>
     */
    protected static function orderNotes(mixed $raw): array
    {
        $notes = static::DEFAULT_ORDER_NOTES;

        foreach (preg_split('/\r?\n/', (string) $raw) ?: [] as $line) {
            if (! preg_match('/^\s*(\d{1,3})\s*[:=]\s*(.+?)\s*$/u', $line, $m)) {
                continue;
            }
            $notes[(int) $m[1]] = mb_substr(trim($m[2]), 0, 400);
        }

        return array_filter($notes, fn ($v) => trim((string) $v) !== '');
    }

    /**
     * Varsayılan beklenti cümleleri. Tarih/gün TAAHHÜDÜ içerenler mağazanın
     * kendi sözüdür — panelden değiştirilebilir olmasının sebebi budur.
     */
    protected const DEFAULT_ORDER_NOTES = [
        // 8 Eyl: "genellikle ertesi iş günü" iddiası KALDIRILDI — kargo çıkış günü
        // artık canlı kargo takviminden söyleniyor (ChatShipping::dispatchNote),
        // ürün sayfasındaki "bugün kargoda" rozetiyle aynı kaynaktan.
        0 => 'Siparişiniz alındı ve hazırlık sürecinde.',
        3 => 'Ödemeniz henüz tamamlanmamış görünüyor; ödeme tamamlandığında siparişiniz hazırlığa alınır.',
        4 => 'Siparişiniz paketleniyor.',
        5 => 'Siparişiniz hazırlanıyor.',
        7 => 'Siparişiniz teslim edilmiş görünüyor. Ürünle ilgili bir sorununuz varsa yardımcı olabilirim.',
        8 => 'Siparişiniz iptal edilmiş görünüyor.',
        9 => 'İade işleminiz tamamlanmış görünüyor.',
        11 => 'İade talebiniz alınmış. Ürün tarafımıza ulaştığında incelenir ve sonucu tarafınıza bildirilir.',
        12 => 'İadeniz tarafımıza ulaşmış ve onaylanmış; ödeme talimatınız oluşturulmuştur.',
        13 => 'İade ödemeniz yapılmış görünüyor.',
        16 => 'Kısmi iade talebiniz alınmış; ürün tarafımıza ulaştığında incelenir.',
        17 => 'Kısmi iade ödemeniz yapılmış görünüyor.',
        18 => 'Kargo teslim edilememiş görünüyor; kargo firması genellikle yeniden teslim denemesi yapar.',
    ];

    /** Ayar değişince çağır (panel kaydet → anında etki). */
    public static function forget(int $tenantId): void
    {
        unset(static::$memo[$tenantId]);
        Cache::forget("chat:tsettings:{$tenantId}");
        ChatKnowledge::forget($tenantId);
    }

    protected static function floatOr($v, float $default): float
    {
        if ($v === null || $v === '' || ! is_numeric($v)) {
            return $default;
        }

        return max(0.0, min(1.0, (float) $v));
    }

    protected static function boolOr($v, bool $default): bool
    {
        if ($v === null || $v === '') {
            return $default;
        }

        return filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
