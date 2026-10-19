<?php

namespace App\Services;

use App\Console\Commands\SyncQuickReplyTemplates;
use App\Http\Controllers\Api\NewConversationController;
use App\Models\QuickReply;

/**
 * Bir tenant'ın panelden gönderebileceği WhatsApp şablonlarının TEK kaynağı.
 *
 * İki küme birleşir:
 *  1) Koddaki ortak şablonlar (WA_TEMPLATES) — {firma} + isteğe bağlı serbest alan
 *  2) O tenant'ın hazır cevaplarından üretilmiş, Meta'da ONAYLANMIŞ şablonlar
 *     (quick_replies.wa_template_name) — değişkensiz, gövde birebir sabit
 *
 * Şablonlar WABA'ya bağlı olduğu için liste tenant'a göre değişir: Boise'nin
 * panelinde Tonny Black'in hazır cevap şablonları GÖRÜNMEZ (görünseydi gönderim
 * "template does not exist" ile düşerdi).
 */
class WhatsAppTemplateRegistry
{
    /** @return array<string, array> key => tanım */
    public static function forTenant(int $tenantId): array
    {
        $out = [];

        foreach (NewConversationController::WA_TEMPLATES as $key => $tpl) {
            $out[$key] = [
                'key' => $key,
                'label' => $tpl['label'],
                'group' => 'Konuşma başlatma',
                'param_label' => $tpl['param_label'],
                'buttons' => $tpl['buttons'] ?? [],
                'body' => $tpl['body'],
                'firm_param' => true,   // gövdedeki {{1}} = firma adı
                'category' => WhatsAppTemplatePolicy::category($tenantId, $key),
            ];
        }

        foreach (self::quickReplyTemplates($tenantId) as $qr) {
            $out[$qr->wa_template_name] = [
                'key' => $qr->wa_template_name,
                'label' => $qr->title,
                'group' => $qr->category ?: 'Hazır cevap',
                'param_label' => null,
                'buttons' => [],
                // Meta'ya gönderilen gövdeyle BİREBİR aynı dönüşüm — panelde
                // görünen metin müşterinin gördüğünden farklı olmasın.
                'body' => SyncQuickReplyTemplates::toTemplateBody($qr->content),
                'firm_param' => false,  // değişken yok
                'category' => $qr->wa_template_category ?: 'UTILITY',
            ];
        }

        return $out;
    }

    public static function find(int $tenantId, string $key): ?array
    {
        return self::forTenant($tenantId)[$key] ?? null;
    }

    /**
     * Meta'ya gidecek gövde parametreleri. Değişkensiz şablonda BOŞ dizi döner —
     * fazladan parametre gönderilirse Meta 400 verir.
     */
    public static function components(array $def, string $firmName, string $param): array
    {
        if (! ($def['firm_param'] ?? false)) {
            return [];
        }

        $params = [['type' => 'text', 'text' => $firmName]];
        if (! empty($def['param_label'])) {
            $params[] = ['type' => 'text', 'text' => $param];
        }

        return [['type' => 'body', 'parameters' => $params]];
    }

    /** Panelde/mesaj kaydında görünecek metin. */
    public static function render(array $def, string $firmName, string $param): string
    {
        return str_replace(['{firma}', '{param}'], [$firmName, $param], $def['body']);
    }

    /** Yalnız Meta'da ONAYLI olanlar gönderilebilir (PENDING/REJECTED listelenmez). */
    protected static function quickReplyTemplates(int $tenantId)
    {
        return QuickReply::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotNull('wa_template_name')
            ->where('wa_template_status', 'APPROVED')
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();
    }
}
