<?php

namespace App\Services;

use App\Http\Controllers\Api\NewConversationController;
use App\Models\Channel;
use App\Models\MarketingSuppression;
use App\Services\Marketing\PhoneQualityGuard;
use Illuminate\Support\Facades\Cache;

/**
 * Panelden gönderilen WhatsApp şablonları için izin/kalite bekçisi.
 *
 * NEDEN: Meta, şablonun kategorisini onay sırasında DEĞİŞTİREBİLİR — aynı metin
 * bir tenant'ta UTILITY, diğerinde MARKETING olabilir (1 Eyl: t1 musait_misiniz
 * MARKETING'e çekildi, t13'te UTILITY kaldı). Bu yüzden kategori sabitten değil,
 * Meta'nın canlı listesinden okunur. MARKETING sayılan bir şablon "Listeden Çık"
 * demiş numaraya gitmemeli: izin ihlali + numara kalitesi düşer.
 *
 * Toplu kampanya yolunu UnifiedSender + PhoneQualityGuard zaten koruyor;
 * burası temsilcinin panelden tek tek gönderdiği şablonları kapsar.
 */
class WhatsAppTemplatePolicy
{
    /**
     * Şablonun Meta'daki GERÇEK kategorisi (UTILITY/MARKETING/AUTHENTICATION).
     * Cache MarketingCampaignController@templates ile ortak — panelden
     * "yenile" denince burası da tazelenir. Meta'ya ulaşılamazsa koddaki
     * kategoriye düşer (bilinmiyorsa MARKETING = temkinli taraf).
     */
    public static function category(int $tenantId, string $templateName): string
    {
        $list = Cache::remember("wa_templates_{$tenantId}", 300, function () use ($tenantId) {
            [$wabaId, $token] = self::credentials($tenantId);
            if (! $wabaId || ! $token) {
                return ['success' => false, 'data' => []];
            }

            return app(WhatsAppService::class)->listTemplates($wabaId, $token);
        });

        foreach ($list['data'] ?? [] as $tpl) {
            if (($tpl['name'] ?? null) === $templateName) {
                return strtoupper($tpl['category'] ?? 'MARKETING');
            }
        }

        return strtoupper(NewConversationController::WA_TEMPLATES[$templateName]['category'] ?? 'MARKETING');
    }

    public static function isMarketing(int $tenantId, string $templateName): bool
    {
        return self::category($tenantId, $templateName) === 'MARKETING';
    }

    /**
     * Gönderim engellenmeli mi? Engellenecekse temsilciye gösterilecek
     * Türkçe mesajı, serbestse null döner.
     */
    public static function blockReason(int $tenantId, string $waId, string $templateName, ?string $label = null): ?string
    {
        if (! self::isMarketing($tenantId, $templateName)) {
            return null;
        }

        if (MarketingSuppression::isSuppressed($tenantId, 'whatsapp', $waId)) {
            return 'Bu numara pazarlama listesinden çıkmış (karalistede). '
                . '"' . ($label ?: NewConversationController::WA_TEMPLATES[$templateName]['label'] ?? $templateName) . '" şablonu '
                . 'Meta tarafından PAZARLAMA sayıldığı için gönderilemez. '
                . 'Müşteri size yazarsa 24 saatlik pencerede serbestçe yanıtlayabilirsiniz.';
        }

        return null;
    }

    /**
     * Numara kalitesi düşükken temsilciye gösterilecek uyarı (yoksa null).
     * Engellemez — RED'de pazarlama şablonu göndermek kaliteyi daha da düşürür,
     * karar temsilcinin.
     */
    public static function qualityWarning(int $tenantId): ?string
    {
        $rating = strtoupper((string) (PhoneQualityGuard::info($tenantId)['quality_rating'] ?? ''));

        return match ($rating) {
            'RED'    => 'WhatsApp numaranızın kalite puanı DÜŞÜK (kırmızı). Pazarlama şablonu göndermek numaranın kısıtlanma riskini artırır — yalnız gerçekten gerekli olduğunda gönderin.',
            'YELLOW' => 'WhatsApp numaranızın kalite puanı ORTA (sarı). Pazarlama şablonlarını seçici kullanın.',
            default  => null,
        };
    }

    /** @return array{0: ?string, 1: ?string} [waba_id, access_token] */
    protected static function credentials(int $tenantId): array
    {
        $channel = Channel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('type', 'like', '%whatsapp%')
            ->where('is_active', true)
            ->first();

        if (! $channel) {
            return [null, null];
        }

        return [
            $channel->settings['business_account_id'] ?? null,
            app(WhatsAppService::class)->getAccessToken($channel->credentials ?? []),
        ];
    }
}
