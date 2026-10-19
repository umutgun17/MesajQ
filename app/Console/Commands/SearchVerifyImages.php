<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ürün görsellerinin CANLILIK denetimi — "fotoğrafsız ürün aramada çıkıyor" kökü.
 *
 * Arama/öneri/chatbot yüzeylerinin "görseli olsun" filtresi
 * (`jsonb_array_length(images) > 0`) yalnız katalogda URL YAZIYOR MU diye bakar.
 * Ticimax feed'i, dosyası CDN'den silinmiş görselin adresini vermeye devam
 * ettiğinden bu filtre delinir: kart döner, <img> 404 alır, widget placeholder
 * (🛍️) basar → kullanıcı fotoğrafsız ürün görür. (2026-09-07 taraması: aramaya
 * girebilen 1.931 üründen 40'ının TÜM görselleri 404.)
 *
 * Çözüm kaynakta: ölü URL `products.images` dizisinden düşülür. Böylece tek bir
 * yerde düzeltip arama, öneri, chatbot, feed ve kreatif modüllerin hepsini
 * birden onarırız — hiçbir sorguya şart eklemek gerekmez. Dizisi boşalan ürün
 * mevcut filtrelerle zaten aramadan düşer.
 *
 * Kendini onarır: gece senkronu görseli yeniden yazar, bu komut yeniden yoklar;
 * mağaza görseli düzeltmişse 200 döner ve ürün aramaya geri gelir.
 *
 * Emniyet:
 *   - Yalnız 404/410 ölü sayılır (timeout/5xx/403 = geçici, dokunulmaz).
 *   - Ölü aday ikinci kez, GET ile teyit edilir (HEAD tuhaflığı + anlık hata).
 *   - Tenant'ın URL'lerinin --max-dead-ratio'sundan fazlası ölü çıkarsa
 *     (CDN kesintisi/toplu 404) HİÇBİR ŞEY yazılmaz, uyarı loglanır.
 */
class SearchVerifyImages extends Command
{
    protected $signature = 'search:verify-images
        {--tenant= : Yalnız bu tenant (boş = görselli aktif ürünü olan tüm tenantlar)}
        {--recheck-days=7 : Sağlam bilinen URL kaç gün sonra yeniden yoklansın}
        {--concurrency=20 : Eşzamanlı istek sayısı}
        {--max-dead-ratio=0.2 : Bu oranı aşan ölü tespitinde güvenlik freni (yazma yok)}
        {--dry : Yazma, yalnız raporla}';

    protected $description = 'Ürün görsel URL\'lerini yoklar, 404 olanları products.images\'tan düşer (fotoğrafsız ürün aramada çıkmasın)';

    /** Ölü sayılan HTTP kodları — kalıcı yokluk. */
    protected const DEAD_CODES = [404, 410];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $recheckDays = max(0, (int) $this->option('recheck-days'));
        $conc = max(1, min(50, (int) $this->option('concurrency')));
        $maxRatio = max(0.01, min(1.0, (float) $this->option('max-dead-ratio')));

        $tenantIds = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : DB::table('products')
                ->where('is_active', true)
                ->whereRaw("jsonb_typeof(images) = 'array' and jsonb_array_length(images) > 0")
                ->distinct()->orderBy('tenant_id')->pluck('tenant_id')->all();

        foreach ($tenantIds as $tid) {
            $this->verifyTenant((int) $tid, $dry, $recheckDays, $conc, $maxRatio);
        }

        return self::SUCCESS;
    }

    protected function verifyTenant(int $tid, bool $dry, int $recheckDays, int $conc, float $maxRatio): void
    {
        $products = DB::table('products')
            ->select('id', 'name', 'images')
            ->where('tenant_id', $tid)
            ->where('is_active', true)
            ->whereRaw("jsonb_typeof(images) = 'array' and jsonb_array_length(images) > 0")
            ->get();

        if ($products->isEmpty()) {
            $this->line("tenant {$tid}: görselli aktif ürün yok, atlandı");

            return;
        }

        /** @var array<string, int[]> $urlToProducts */
        $urlToProducts = [];
        foreach ($products as $p) {
            foreach ($this->imagesOf($p->images) as $url) {
                $urlToProducts[$url][] = (int) $p->id;
            }
        }
        $urls = array_keys($urlToProducts);
        $this->info("tenant {$tid}: {$products->count()} ürün, ".count($urls).' tekil görsel URL');

        // Yakın zamanda 200 dönmüş URL'i yeniden yoklama; ölü bilinenleri DAİMA
        // yeniden yokla (mağaza düzeltmişse ürün aramaya geri dönsün).
        $known = DB::table('product_image_checks')
            ->whereIn('url_hash', array_map('md5', $urls))
            ->get()->keyBy('url_hash');

        $fresh = now()->subDays($recheckDays);
        $toCheck = [];
        foreach ($urls as $u) {
            $k = $known[md5($u)] ?? null;
            if ($k && ! $k->is_dead && $k->checked_at && $k->checked_at >= $fresh->toDateTimeString()) {
                continue; // sağlam ve taze
            }
            $toCheck[] = $u;
        }
        $this->line('  yoklanacak: '.count($toCheck).' (önbellekten atlanan: '.(count($urls) - count($toCheck)).')');

        $status = count($toCheck) ? $this->probe($toCheck, $conc, 'HEAD') : [];

        // İkinci teyit: 404/410 dönenleri GET ile bir kez daha sor (HEAD tuhaflığı
        // ve anlık hatalar tek başına ürünü aramadan düşürmesin).
        $candidates = array_keys(array_filter($status, fn ($c) => in_array($c, self::DEAD_CODES, true)));
        if ($candidates) {
            $this->line('  ölü aday: '.count($candidates).' → GET ile teyit ediliyor');
            $confirm = $this->probe($candidates, min($conc, 8), 'GET');
            foreach ($confirm as $u => $c) {
                $status[$u] = $c;
            }
        }

        $deadNow = [];
        foreach ($status as $u => $c) {
            if (in_array($c, self::DEAD_CODES, true)) {
                $deadNow[$u] = true;
            }
        }

        // Önceki koşularda ölü bulunmuş ve bu koşuda da ölü kalanlar zaten yukarıda;
        // güvenlik freni tenant'ın TÜM URL'leri üzerinden hesaplanır.
        $ratio = count($urls) ? count($deadNow) / count($urls) : 0.0;
        $this->line('  ölü URL: '.count($deadNow).' (%'.number_format($ratio * 100, 2).')');

        $this->persistChecks($status, $known, $dry);

        if (! $deadNow) {
            $this->info("  tenant {$tid}: temiz, düşülecek görsel yok");

            return;
        }

        if ($ratio > $maxRatio) {
            $msg = "search:verify-images tenant {$tid}: ölü oranı %".number_format($ratio * 100, 1)
                .' (eşik %'.number_format($maxRatio * 100, 1).') — CDN kesintisi olabilir, GÖRSEL DÜŞÜLMEDİ';
            $this->error('  '.$msg);
            Log::warning($msg);

            return;
        }

        // ── products.images'tan ölü URL'leri düş ──
        $pruned = 0;
        $emptied = [];
        foreach ($products as $p) {
            $imgs = $this->imagesOf($p->images);
            $keep = array_values(array_filter($imgs, fn ($u) => ! isset($deadNow[$u])));
            if (count($keep) === count($imgs)) {
                continue;
            }
            $pruned += count($imgs) - count($keep);
            if (! $keep) {
                $emptied[] = ['id' => (int) $p->id, 'name' => $p->name];
            }
            if (! $dry) {
                DB::table('products')->where('id', $p->id)->update([
                    'images' => json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            }
        }

        $prefix = $dry ? '  [DRY] ' : '  ';
        $this->info($prefix.$pruned.' ölü görsel düşüldü; '.count($emptied).' ürünün hiç görseli kalmadı (aramadan düşer)');
        foreach (array_slice($emptied, 0, 20) as $e) {
            $this->line("    #{$e['id']} {$e['name']}");
        }
        if (count($emptied) > 20) {
            $this->line('    ... +'.(count($emptied) - 20).' ürün daha');
        }
        if ($emptied && ! $dry) {
            Log::info('search:verify-images: görselsiz kalan ürünler', [
                'tenant_id' => $tid,
                'count' => count($emptied),
                'product_ids' => array_column($emptied, 'id'),
            ]);
        }
    }

    /**
     * URL'leri paralel yoklar.
     *
     * @param  string[]  $urls
     * @return array<string, int>  url => HTTP kodu (0 = ağ hatası/timeout)
     */
    protected function probe(array $urls, int $conc, string $method): array
    {
        $out = [];
        $bar = $this->output->createProgressBar(count($urls));
        $bar->start();

        foreach (array_chunk($urls, $conc) as $chunk) {
            $map = [];   // hash => url (pool anahtarları)
            $responses = Http::pool(function (Pool $pool) use ($chunk, $method, &$map) {
                $reqs = [];
                foreach ($chunk as $u) {
                    $h = md5($u);
                    $map[$h] = $u;
                    $req = $pool->as($h)
                        ->timeout(15)
                        ->connectTimeout(8)
                        ->withHeaders(['User-Agent' => 'YunbiImageCheck/1.0']);
                    $reqs[] = $method === 'GET' ? $req->get($u) : $req->head($u);
                }

                return $reqs;
            });

            foreach ($map as $h => $u) {
                $r = $responses[$h] ?? null;
                $out[$u] = $r instanceof Response ? $r->status() : 0;
            }
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine();

        return $out;
    }

    /**
     * Yoklama sonuçlarını kaydeder (upsert).
     *
     * @param  array<string, int>  $status
     */
    protected function persistChecks(array $status, $known, bool $dry): void
    {
        if ($dry || ! $status) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($status as $url => $code) {
            $hash = md5($url);
            $prev = $known[$hash] ?? null;
            $dead = in_array($code, self::DEAD_CODES, true);
            $rows[] = [
                'url_hash' => $hash,
                'url' => $url,
                'status' => $code,
                'is_dead' => $dead,
                'fail_count' => $dead ? (int) ($prev->fail_count ?? 0) + 1 : 0,
                'first_dead_at' => $dead ? ($prev->first_dead_at ?? $now) : null,
                'checked_at' => $now,
                'created_at' => $prev->created_at ?? $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('product_image_checks')->upsert(
                $chunk,
                ['url_hash'],
                ['url', 'status', 'is_dead', 'fail_count', 'first_dead_at', 'checked_at', 'updated_at']
            );
        }
    }

    /** @return string[] */
    protected function imagesOf($raw): array
    {
        $arr = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($arr)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($u) => is_string($u) ? trim($u) : '',
            $arr
        ), fn ($u) => $u !== '' && str_starts_with($u, 'http')));
    }
}
