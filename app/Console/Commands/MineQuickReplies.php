<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Geçmiş temsilci mesajlarından hazır cevap ADAYLARI çıkarır (AI YOK, ücretsiz).
 *
 * Yöntem: mesajı normalleştir (küçült, rakam/emoji/noktalama at) → aynı
 * normale düşenleri grupla → benzer grupları birleştir (Levenshtein) →
 * frekansa göre sırala. Çıktı JSON; metinler HAM, insan/asistan elden
 * geçirmeden DB'ye yazılmaz.
 */
class MineQuickReplies extends Command
{
    protected $signature = 'messages:mine-quick-replies
                            {tenant : Tenant ID}
                            {--min=3 : Bir adayın en az kaç kez geçmesi gerektiği}
                            {--limit=100 : Kaç aday döndürüleceği}
                            {--min-len=15 : Bundan kısa mesajlar elenir}
                            {--max-len=700 : Bundan uzun mesajlar elenir}
                            {--out= : JSON çıktı dosyası}';

    protected $description = 'Geçmiş temsilci mesajlarından hazır cevap adaylarını istatistiksel olarak çıkarır';

    public function handle(): int
    {
        $tenantId = (int) $this->argument('tenant');
        $min = (int) $this->option('min');
        $limit = (int) $this->option('limit');
        $minLen = (int) $this->option('min-len');
        $maxLen = (int) $this->option('max-len');

        $this->info("Tenant {$tenantId} temsilci mesajları taranıyor...");

        $groups = [];   // normal => ['count' => n, 'samples' => [orijinal => kaç kez]]
        $scanned = 0;

        DB::table('messages')
            ->where('tenant_id', $tenantId)
            ->where('sender_type', 'agent')
            ->whereNotNull('content')
            ->orderBy('id')
            ->select('content')
            ->chunk(5000, function ($rows) use (&$groups, &$scanned, $minLen, $maxLen) {
                foreach ($rows as $row) {
                    $scanned++;
                    $original = trim(preg_replace('/\s+/u', ' ', (string) $row->content));
                    $len = mb_strlen($original);
                    if ($len < $minLen || $len > $maxLen) {
                        continue;
                    }

                    $norm = $this->normalize($original);
                    if (mb_strlen($norm) < $minLen) {
                        continue;
                    }

                    if (! isset($groups[$norm])) {
                        $groups[$norm] = ['count' => 0, 'samples' => []];
                    }
                    $groups[$norm]['count']++;
                    $groups[$norm]['samples'][$original] = ($groups[$norm]['samples'][$original] ?? 0) + 1;
                }
            });

        $this->line("Taranan mesaj: {$scanned} | ham grup: " . count($groups));

        // Eşiği geçenler
        $groups = array_filter($groups, fn ($g) => $g['count'] >= $min);
        uasort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);

        // Yakın grupları birleştir — ilk 400 grupla sınırlı (O(n²) maliyeti için)
        $top = array_slice($groups, 0, 400, true);
        $merged = [];
        foreach ($top as $norm => $data) {
            $hit = null;
            foreach ($merged as $mNorm => $mData) {
                if ($this->similar($norm, $mNorm)) {
                    $hit = $mNorm;
                    break;
                }
            }
            if ($hit !== null) {
                $merged[$hit]['count'] += $data['count'];
                foreach ($data['samples'] as $s => $n) {
                    $merged[$hit]['samples'][$s] = ($merged[$hit]['samples'][$s] ?? 0) + $n;
                }
            } else {
                $merged[$norm] = $data;
            }
        }

        uasort($merged, fn ($a, $b) => $b['count'] <=> $a['count']);
        $merged = array_slice($merged, 0, $limit, true);

        $out = [];
        $i = 0;
        foreach ($merged as $norm => $data) {
            arsort($data['samples']);
            $variants = array_slice(array_keys($data['samples']), 0, 3);
            $out[] = [
                'sira' => ++$i,
                'adet' => $data['count'],
                'ornek' => $variants[0] ?? '',
                'varyantlar' => array_slice($variants, 1),
            ];
        }

        $path = $this->option('out') ?: storage_path("app/quick-reply-adaylari-t{$tenantId}.json");
        file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        foreach (array_slice($out, 0, 40) as $row) {
            $this->line(str_pad('x' . $row['adet'], 7) . mb_substr($row['ornek'], 0, 110));
        }

        $this->info(count($out) . " aday yazıldı → {$path}");

        return self::SUCCESS;
    }

    /** Karşılaştırma için sadeleştirilmiş biçim. */
    private function normalize(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        // Emoji ve sembolleri at
        $t = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{2B00}-\x{2BFF}]/u', ' ', $t);
        // Rakamları (numara, cm, tarih, sipariş no) tek sembole indir
        $t = preg_replace('/\d+/u', '#', $t);
        // Noktalama
        $t = preg_replace('/[^\p{L}\p{N}#\s]/u', ' ', $t);

        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    /** İki normal metin "aynı cevabın varyantı" mı? */
    private function similar(string $a, string $b): bool
    {
        $la = mb_strlen($a);
        $lb = mb_strlen($b);
        if ($la === 0 || $lb === 0) {
            return false;
        }
        // Uzunluk farkı %35'ten fazlaysa aynı cevap değildir
        if (min($la, $lb) / max($la, $lb) < 0.65) {
            return false;
        }
        // levenshtein 255 bayt sınırlı → kısaltarak karşılaştır
        $sa = mb_substr($a, 0, 120);
        $sb = mb_substr($b, 0, 120);
        $dist = levenshtein($sa, $sb);

        return $dist / max(mb_strlen($sa), mb_strlen($sb)) < 0.25;
    }
}
