<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\QuickReply;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Hazır cevapları WhatsApp şablonuna çevirir (Meta onayına gönderir).
 *
 * Neden: 24 saatlik pencere kapandığında temsilci hazır cevabı düz mesaj
 * olarak gönderemiyor. Aynı metnin onaylı şablon karşılığı olursa panelden
 * pencere kapalıyken de gönderilebiliyor.
 *
 *   php artisan wa:sync-quick-replies --tenant=1 --dry-run
 *   php artisan wa:sync-quick-replies --tenant=1
 *   php artisan wa:sync-quick-replies --tenant=1 --refresh   (yalnız durum günceller)
 */
class SyncQuickReplyTemplates extends Command
{
    protected $signature = 'wa:sync-quick-replies
                            {--tenant=1 : Tenant ID}
                            {--dry-run : Meta\'ya hiçbir şey göndermeden ne olacağını göster}
                            {--refresh : Yeni şablon açma, mevcutların Meta durumunu güncelle}
                            {--only= : Yalnız bu hazır cevap ID\'leri (virgülle)}';

    protected $description = 'Hazır cevapları WhatsApp şablonu olarak Meta onayına gönderir';

    /** Meta gövde sınırı. */
    protected const BODY_LIMIT = 1024;

    public function handle(WhatsAppService $wa): int
    {
        $tenantId = (int) $this->option('tenant');
        $dry = (bool) $this->option('dry-run');

        $channel = Channel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('type', 'like', '%whatsapp%')
            ->where('is_active', true)
            ->first();

        if (! $channel) {
            $this->error("t{$tenantId}: aktif WhatsApp kanalı yok.");

            return self::FAILURE;
        }

        $wabaId = $channel->settings['business_account_id'] ?? null;
        $token = $wa->getAccessToken($channel->credentials ?? []);
        if (! $wabaId || ! $token) {
            $this->error("t{$tenantId}: WABA kimlik bilgileri eksik.");

            return self::FAILURE;
        }

        // Meta'daki mevcut şablonlar: hem çakışan ad tespiti hem durum tazeleme.
        $live = collect($wa->listTemplates($wabaId, $token)['data'] ?? [])
            ->keyBy(fn ($t) => $t['name']);

        if ($this->option('refresh')) {
            return $this->refresh($tenantId, $live);
        }

        $query = QuickReply::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('id');

        if ($only = $this->option('only')) {
            $query->whereIn('id', array_filter(array_map('intval', explode(',', $only))));
        }

        $rows = $query->get();
        $created = $skipped = $failed = 0;

        foreach ($rows as $qr) {
            $body = self::toTemplateBody($qr->content);
            $skip = self::skipReason($qr, $body);

            if ($skip) {
                $this->line(sprintf('  ⏭  [%d] %-38s %s', $qr->id, mb_substr($qr->title, 0, 38), $skip));
                $skipped++;

                continue;
            }

            $name = self::slug($qr->title, $qr->id);

            // Zaten Meta'da varsa yeniden açma — yalnız eşlemeyi kaydet.
            if ($live->has($name)) {
                if (! $dry) {
                    $qr->forceFill([
                        'wa_template_name' => $name,
                        'wa_template_status' => $live[$name]['status'] ?? null,
                        'wa_template_category' => $live[$name]['category'] ?? null,
                        'wa_template_synced_at' => now(),
                    ])->saveQuietly();
                }
                $this->line(sprintf('  ↩  [%d] %-38s zaten var (%s)', $qr->id, mb_substr($qr->title, 0, 38), $live[$name]['status'] ?? '?'));
                $skipped++;

                continue;
            }

            if ($dry) {
                $this->line(sprintf('  +  [%d] %-38s → %s (%d karakter)', $qr->id, mb_substr($qr->title, 0, 38), $name, mb_strlen($body)));
                $created++;

                continue;
            }

            $res = $wa->createTemplate($wabaId, $token, [
                'name' => $name,
                'category' => 'UTILITY',
                'language' => 'tr',
                'allow_category_change' => true,
                'components' => [['type' => 'BODY', 'text' => $body]],
            ]);

            if (empty($res['success'])) {
                $err = $res['error']['error']['error_user_msg']
                    ?? $res['error']['error']['message']
                    ?? 'bilinmeyen hata';
                $this->line(sprintf('  ❌ [%d] %-38s %s', $qr->id, mb_substr($qr->title, 0, 38), $err));
                $failed++;

                continue;
            }

            $qr->forceFill([
                'wa_template_name' => $name,
                'wa_template_status' => $res['data']['status'] ?? 'PENDING',
                'wa_template_category' => $res['data']['category'] ?? null,
                'wa_template_synced_at' => now(),
            ])->saveQuietly();

            $this->line(sprintf('  ✅ [%d] %-38s → %s', $qr->id, mb_substr($qr->title, 0, 38), $name));
            $created++;

            // Meta yazma uçlarında ard arda istek sınırı var — nazik davran.
            usleep(400_000);
        }

        Cache::forget("wa_templates_{$tenantId}");
        $this->newLine();
        $this->info(sprintf('%s — açılan: %d, atlanan: %d, hata: %d', $dry ? 'KURU ÇALIŞTIRMA' : 'Bitti', $created, $skipped, $failed));

        return self::SUCCESS;
    }

    /** Meta'daki durumları yerele yazar (onay/red takibi). */
    protected function refresh(int $tenantId, $live): int
    {
        $n = 0;
        $rows = QuickReply::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('wa_template_name')
            ->get();

        foreach ($rows as $qr) {
            $tpl = $live[$qr->wa_template_name] ?? null;
            $qr->forceFill([
                // Meta'dan düşmüşse (silinmiş) eşlemeyi bozma, durumu boşalt.
                'wa_template_status' => $tpl['status'] ?? null,
                'wa_template_category' => $tpl['category'] ?? $qr->wa_template_category,
                'wa_template_synced_at' => now(),
            ])->saveQuietly();
            $n++;
        }

        Cache::forget("wa_templates_{$tenantId}");
        $this->info("{$n} eşleme güncellendi.");
        $counts = QuickReply::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('wa_template_name')
            ->selectRaw('wa_template_status, wa_template_category, count(*) as n')
            ->groupBy('wa_template_status', 'wa_template_category')
            ->get();
        foreach ($counts as $c) {
            $this->line(sprintf('  %-10s %-10s %d', $c->wa_template_status ?? '(yok)', $c->wa_template_category ?? '-', $c->n));
        }

        return self::SUCCESS;
    }

    /**
     * Hazır cevap metnini şablon gövdesine çevirir.
     * - WhatsApp kalın yazımı tek yıldız: **x** → *x* (çift yıldız düz metin çıkar)
     * - Meta 4+ ard arda satır sonunu reddeder; boş satırları sadeleştir
     */
    public static function toTemplateBody(string $content): string
    {
        $body = preg_replace('/\*\*(.+?)\*\*/us', '*$1*', $content);
        $body = preg_replace('/[ \t]+\n/u', "\n", (string) $body);   // satır sonu boşlukları
        $body = preg_replace('/\n{3,}/u', "\n\n", (string) $body);   // 3+ satır → 2

        return trim((string) $body);
    }

    /** Şablona çevrilemeyecek hazır cevaplar için sebep (uygunsa null). */
    public static function skipReason(QuickReply $qr, string $body): ?string
    {
        if ($qr->wa_template_name) {
            return 'zaten eşlenmiş (' . $qr->wa_template_name . ')';
        }
        if (mb_strlen($body) > self::BODY_LIMIT) {
            return 'çok uzun (' . mb_strlen($body) . ' > ' . self::BODY_LIMIT . ')';
        }
        // Harf içermeyen (yalnız emoji/noktalama) gövdeyi Meta reddeder.
        if (! preg_match('/\p{L}{3,}/u', $body)) {
            return 'metin yok (yalnız emoji/işaret) — şablon olamaz';
        }

        return null;
    }

    /** Başlıktan Meta şablon adı: yalnız a-z0-9_ , Türkçe harfler çevrilir. */
    public static function slug(string $title, int $id): string
    {
        $map = ['ı' => 'i', 'İ' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g',
            'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c'];
        $s = mb_strtolower(strtr($title, $map), 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/u', '_', $s);
        $s = trim((string) $s, '_');
        $s = preg_replace('/_+/', '_', (string) $s);
        $s = mb_substr((string) $s, 0, 50);
        $s = trim($s, '_');

        // Başlıklar tekrar edebiliyor (ör. iki "İade inceleme…") — id ile tekilleştir.
        return ($s === '' ? 'hazir_cevap' : $s) . '_' . $id;
    }
}
