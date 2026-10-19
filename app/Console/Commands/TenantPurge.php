<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * ⚠️ GERİ DÖNÜŞSÜZ: Bir tenant'ın TÜM verisini siler — DB (tenant_id taşıyan
 * bütün tablolar, şemadan otomatik keşif) + sunucu dosyaları (kreatif medya,
 * kimlik dosyaları, iade ekleri, feed kaynakları, widget cache, çağrı kayıtları)
 * + tenant satırının kendisi (forceDelete).
 *
 * Güvence: hem tenant ID hem SLUG birebir verilmek zorunda; --force şart.
 * FK sıralaması bilinmediğinden çok-geçişli silme yapılır (ihlal olan tablo
 * sonraki tura kalır; ilerleme durursa hata listelenir).
 */
class TenantPurge extends Command
{
    protected $signature = 'tenant:purge {id : Tenant ID} {slug : Onay için tenant slug\'ı (birebir)} {--force : Onay}';

    protected $description = '⚠️ Tenant\'ın tüm verisini DB + sunucudan GERİ DÖNÜŞSÜZ siler';

    public function handle(): int
    {
        $tenant = Tenant::withTrashed()->find((int) $this->argument('id'));

        if (! $tenant) {
            $this->error('Tenant bulunamadı.');

            return self::FAILURE;
        }

        if ($this->argument('slug') !== $tenant->slug) {
            $this->error("Slug eşleşmedi (beklenen: {$tenant->slug}) — silme reddedildi.");

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error('--force bayrağı olmadan silinmez.');

            return self::FAILURE;
        }

        $id = $tenant->id;
        $this->warn("SİLİNİYOR: #{$id} {$tenant->name} ({$tenant->slug})");

        // 1) tenant_id kolonu olan tüm tablolar (tenants hariç)
        $tables = collect(DB::select("
            SELECT table_name FROM information_schema.columns
            WHERE table_schema = 'public' AND column_name = 'tenant_id'
        "))->pluck('table_name')->reject(fn ($t) => $t === 'tenants')->values()->all();

        $deleted = [];
        $pending = $tables;
        for ($pass = 1; $pass <= 5 && $pending; $pass++) {
            $stillPending = [];
            foreach ($pending as $table) {
                try {
                    $n = DB::table($table)->where('tenant_id', $id)->delete();
                    $deleted[$table] = ($deleted[$table] ?? 0) + $n;
                } catch (\Throwable $e) {
                    $stillPending[] = $table; // FK ihlali → sonraki geçiş
                }
            }
            if (count($stillPending) === count($pending)) {
                break; // ilerleme yok
            }
            $pending = $stillPending;
        }

        if ($pending) {
            $this->error('Silinemeyen tablolar: ' . implode(', ', $pending));

            return self::FAILURE;
        }

        foreach (array_filter($deleted) as $t => $n) {
            $this->line("  {$t}: {$n}");
        }

        // 2) Sunucu dosyaları
        $paths = [
            public_path("creative/{$id}"),
            storage_path("app/ga4/{$id}.json.enc"),
            storage_path("app/google/{$id}.json.enc"),
            storage_path("app/returns/{$id}"),
            storage_path("app/feed-sources/{$id}"),
            storage_path("app/calls/{$id}"),
            public_path("widget/data/{$tenant->slug}.json"),
        ];
        foreach ($paths as $p) {
            if (is_dir($p)) {
                File::deleteDirectory($p);
                $this->line("  dosya: {$p} (klasör silindi)");
            } elseif (is_file($p)) {
                @unlink($p);
                $this->line("  dosya: {$p} silindi");
            }
        }

        // 3) Tenant satırı
        $tenant->forceDelete();

        Log::warning('tenant:purge tamamlandı', ['tenant_id' => $id, 'slug' => $this->argument('slug'), 'tables' => array_filter($deleted)]);
        $this->info('Tenant kalıcı olarak silindi.');

        return self::SUCCESS;
    }
}
