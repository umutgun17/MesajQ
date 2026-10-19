<?php

namespace App\Console\Commands;

use App\Models\QuickReply;
use Illuminate\Console\Command;

/**
 * Elden geçirilmiş varsayılan hazır cevap setini bir tenant'a yükler.
 *
 * Kaynak JSON: database/data/quick-replies-t{ID}.json
 * [{"category": "...", "title": "...", "content": "..."}, ...]
 *
 * Idempotent: aynı başlık zaten varsa atlanır (tenant'ın kendi düzenlemesi ezilmez).
 */
class SeedQuickReplies extends Command
{
    protected $signature = 'quick-replies:seed
                            {tenant : Tenant ID}
                            {--file= : JSON dosya yolu (varsayılan database/data/quick-replies-t{ID}.json)}
                            {--dry-run : Sadece ne yükleneceğini göster}';

    protected $description = 'Varsayılan hazır cevap setini tenant\'a yükler';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant');
        $file = $this->option('file') ?: database_path("data/quick-replies-t{$tenantId}.json");

        if (! is_file($file)) {
            $this->error("Dosya bulunamadı: {$file}");

            return self::FAILURE;
        }

        $rows = json_decode(file_get_contents($file), true);
        if (! is_array($rows)) {
            $this->error('JSON okunamadı.');

            return self::FAILURE;
        }

        // Global scope auth'a bağlı; konsolda auth yok → tenant'ı elle filtreliyoruz.
        $mevcut = QuickReply::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->pluck('title')
            ->all();

        $eklenen = 0;
        $atlanan = 0;
        $sira = 0;

        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $content = trim((string) ($row['content'] ?? ''));
            if ($title === '' || $content === '') {
                continue;
            }

            $sira += 10;

            if (in_array($title, $mevcut, true)) {
                $atlanan++;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("+ [{$row['category']}] {$title}");
                $eklenen++;
                continue;
            }

            QuickReply::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tenantId,
                'category' => trim((string) ($row['category'] ?? 'Genel')) ?: 'Genel',
                'title' => $title,
                'content' => $content,
                'is_active' => true,
                'is_default' => true,
                'sort_order' => $sira,
                'created_by' => null,
            ]);
            $eklenen++;
        }

        $this->info("Tenant {$tenantId}: {$eklenen} eklendi, {$atlanan} atlandı (zaten vardı).");

        return self::SUCCESS;
    }
}
