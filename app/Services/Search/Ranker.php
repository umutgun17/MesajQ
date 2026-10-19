<?php

namespace App\Services\Search;

/**
 * Akıllı Arama — nihai sıralama (ranking).
 *
 * Retriever adaylarının ham sinyallerini 0..1'e normalize edip config ağırlıklarıyla
 * harmanlar: semantic + lexical + performance_score + GA4 + stok. Stoksuz ürün cezalandırılır.
 */
class Ranker
{
    /**
     * @param  array<int, array<string, mixed>>  $candidates  Retriever çıktısı
     * @param  array  $u  QueryUnderstanding çıktısı (intent/facet — ileride boost için)
     * @param  int|null  $limit  null → config result_limit; sayfalama için tüm adaylar skorlanabilir
     * @return array<int, array<string, mixed>>
     */
    public function rank(array $candidates, array $u = [], ?int $limit = null): array
    {
        if (empty($candidates)) {
            return [];
        }

        $w = (array) config('search.ranking.weights', []);
        $oosPenalty = (float) config('search.ranking.out_of_stock_penalty', 0.5);
        $resultLimit = $limit ?? (int) config('search.retrieval.result_limit', 24);

        // Normalizasyon referansları (aday havuzu içinde)
        $maxPerf = max(1e-9, max(array_map(fn ($c) => (float) ($c['performance_score'] ?? 0), $candidates)));
        $maxPurchases = max(0.0, max(array_map(fn ($c) => (float) ($c['ga4_purchases'] ?? 0), $candidates)));

        foreach ($candidates as &$c) {
            $distance = $c['distance'] ?? null;
            $semantic = $distance === null ? 0.0 : max(0.0, 1.0 - (float) $distance); // cosine mesafe → benzerlik
            $lexical = max(0.0, min(1.0, (float) ($c['name_sim'] ?? 0)));
            $perf = max(0.0, (float) ($c['performance_score'] ?? 0)) / $maxPerf;
            $purchases = (float) ($c['ga4_purchases'] ?? 0);
            $ga4 = $maxPurchases > 0 ? (log(1 + $purchases) / log(1 + $maxPurchases)) : 0.0;
            // Renk/beden facet'li aramada stok = ARANAN varyantın stoğu (facet_in_stock);
            // yoksa ürün toplam stoğu. 44 arayana 44'ü tükenmişi öne çıkarma.
            $facetStock = $c['facet_in_stock'] ?? null;
            $inStock = $facetStock !== null
                ? ($facetStock === true || $facetStock === 't' || $facetStock === 1 || $facetStock === '1' || $facetStock === 'true')
                : ((float) ($c['stock_quantity'] ?? 0)) > 0;
            $stock = $inStock ? 1.0 : 0.0;

            $score = ($w['semantic'] ?? 0) * $semantic
                + ($w['lexical'] ?? 0) * $lexical
                + ($w['performance'] ?? 0) * $perf
                + ($w['ga4'] ?? 0) * $ga4
                + ($w['stock'] ?? 0) * $stock;

            if (! $inStock) {
                $score *= $oosPenalty;
            }

            // Ürün kodu tam eşleşmesi (Retriever code_hit) — kullanıcı belirli bir kodu
            // yazdıysa o ürün(ler) popüler kardeş kodların (TBARZ1010 vs 1015) önünde
            // code_hit: 2 = SKU birebir, 1 = kısmi kod eşleşmesi, 0 = yok
            $codeHit = $this->level($c['code_hit'] ?? null);

            // Tam-kelime eşleşmesi (Retriever exact_hit) — terim isimde bağımsız kelimeyse
            // ("kedi" ⊂ "Kedi Taşıma Çantası") bulanık typo-bandı eşleşmelerinin (kemer≈kedi)
            // popülerlikle öne geçmesini engeller: stok bloğu içinde ayrı kademe.
            // exact_hit = isimde birebir geçen terim sayısı (çok kelimede tümü tutan önde)
            $exactHit = $this->level($c['exact_hit'] ?? null);

            $c['_score'] = round($score, 6);
            $c['_code_hit'] = $codeHit;
            $c['_exact_hit'] = $exactHit;
            $c['_in_stock'] = $inStock ? 1 : 0;
            $c['_signals'] = [
                'semantic' => round($semantic, 4),
                'lexical' => round($lexical, 4),
                'performance' => round($perf, 4),
                'ga4' => round($ga4, 4),
                'stock' => $stock,
            ];
        }
        unset($c);

        // Önce satın alınabilir olan: stokta (renk/beden aramada o varyantı stokta)
        // olanlar bloğu, sonra tükenenler — blok içinde skor sırası. Son kırıcı id:
        // sayfalamada (offset) sıralama istekler arası DETERMİNİSTİK kalmalı.
        // oos_direct (stoksuz ürünü doğrudan aramada getiren tenant): stok bloğu kod eşleşmesinden de
        // önce gelir — birebir SKU'su yazılan ürün tükenmişse bile listenin EN ALTINDA durur.
        $stockFirst = ! empty($u['_oos_direct']);
        usort($candidates, fn ($a, $b) => ($stockFirst ? ($b['_in_stock'] <=> $a['_in_stock']) : 0)
            ?: ($b['_code_hit'] <=> $a['_code_hit'])
            ?: ($b['_in_stock'] <=> $a['_in_stock'])
            ?: ($b['_exact_hit'] <=> $a['_exact_hit'])
            ?: ($b['_score'] <=> $a['_score'])
            ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0)));

        return array_slice($candidates, 0, $resultLimit);
    }

    /** DB'den gelen kademe sinyali (bool/'t'/int) → int. */
    protected function level(mixed $v): int
    {
        if ($v === true || $v === 't' || $v === 'true') {
            return 1;
        }

        return max(0, (int) $v);
    }
}
