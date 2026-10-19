<?php

namespace App\Services\Ai;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Kiracının KENDİ Claude anahtarını hangi modüllerin harcayabileceğini belirler.
 *
 * Neden: kiracı panele anahtarı chatbot için giriyor, ama aynı `settings.claude_api_key`
 * alanını gelen kutusu AI önerileri (ClaudeService), sosyal medya (SocialAi) ve çağrı
 * analizi (call:transcribe / call:process) de okuyordu. Gelen kutusu önerisi her gelen
 * müşteri mesajında OTOMATİK tetiklendiği ve prompt'u RAG + CRM bağlamıyla şiştiği için
 * chatbot'un kat kat üzerinde bakiye yakıyordu (5 Eyl ölçümü: ~0,02 USD/öneri, ~110
 * öneri/gün ≈ 2,2 USD/gün — chatbot ise ~0,0026 USD/mesaj).
 *
 * VARSAYILAN: yalnız chatbot açık. Diğer modüller kiracı panelden açıkça açana kadar
 * Claude çağrısı YAPMAZ (her birinin kendi ücretsiz/yerel yedeği devreye girer).
 */
class ClaudeKeyPolicy
{
    /** Modül → tenant ayar anahtarı. Chatbot listede yok: her zaman serbest. */
    protected const FLAGS = [
        'inbox'  => 'ai_inbox_enabled',   // gelen kutusu AI önerisi + otomatik yanıt
        'social' => 'ai_social_enabled',  // sosyal medya üretimi/görsel puanlama
        'calls'  => 'ai_calls_enabled',   // çağrı kaydı transkript analizi
    ];

    /** İlgili modül bu kiracı için Claude çağrısı yapabilir mi? */
    public static function allows(?int $tenantId, string $feature): bool
    {
        $flag = self::FLAGS[$feature] ?? null;
        if ($flag === null) {
            return true; // bilinmeyen/gate'siz modül (chatbot) — serbest
        }

        try {
            $settings = $tenantId ? (Tenant::find($tenantId)?->settings ?? []) : [];

            return filter_var($settings[$flag] ?? false, FILTER_VALIDATE_BOOLEAN);
        } catch (\Throwable $e) {
            // Fail-closed: ayar okunamıyorsa para harcama.
            Log::warning('ClaudeKeyPolicy: ayar okunamadı, kapalı sayılıyor', [
                'tenant_id' => $tenantId,
                'feature' => $feature,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
