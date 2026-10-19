<?php

namespace App\Services\Chat;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * AI Chatbot — KARGOYA ÇIKIŞ BEKLENTİSİ, ürün detaydaki rozetle AYNI kaynaktan.
 *
 * Neden var (8 Eyl 2026, kullanıcı bildirimi + sohbet #722):
 *   Müşteri: "Aynı gün kargo bildirgesi olmasına rağmen nasıl 1-3 iş günü
 *   diyebiliyorsunuz?"
 * Ürün detay sayfasındaki "Bugün Kargoda" rozeti (public/widget/shipping.js)
 * kesim saatini biliyor ve "2 sa 14 dk içinde sipariş verirsen BUGÜN kargoda"
 * diyor; chatbot ise personelin eski hazır cevabından "1-3 iş günü içinde
 * kargoya verilir" diyordu. Aynı mağaza, iki farklı söz.
 *
 * Bu sınıf rozetin `nextShip()` algoritmasının PHP karşılığıdır ve AYNI tenant
 * ayarlarını okur (shipping_day_cutoffs / shipping_days + shipping_cutoff_time
 * + shipping_friday_extension). Rozet panelden değişince bot da o an değişir —
 * ikinci bir yerde metin güncellemek gerekmez.
 *
 * ⚠️ Kapsam: bu sınıf yalnız KARGOYA TESLİM anını söyler. Kargonun yolda geçen
 * süresi (şehre göre 1-3 iş günü) taşıyıcının işidir; o cümle mağazanın kendi
 * hazır cevabından gelir (transitNote).
 */
class ChatShipping
{
    public const TZ = 'Europe/Istanbul';

    /** ISO 1=Pazartesi … 7=Pazar */
    public const DAY_NAMES = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];

    /** Kargoya çıkış sonrası taşıma süresi — mağazanın beyanı, sabit değil. */
    protected const DEFAULT_TRANSIT = 'Kargoya verildikten sonra teslimat, bulunduğunuz şehre göre 1-3 iş günü sürer.';

    /**
     * Kesim takvimi: [{d: ISO 1-7, h, m, prev}]
     * `prev=true` → kesim, kargo gününün BİR ÖNCEKİ gününe düşer
     * (ör. "Cumartesi kargosu için Cuma 23:59'a kadar sipariş").
     *
     * Widget kapalıysa (shipping_widget_enabled=false) boş döner — mağaza bu
     * taahhüdü vitrinde vermiyorsa bot da vermemelidir.
     *
     * @return array<int, array{d: int, h: int, m: int, prev: bool}>
     */
    public static function schedule(int $tenantId): array
    {
        return Cache::remember("chat:shipping:{$tenantId}", 300, function () use ($tenantId) {
            $tenant = Tenant::find($tenantId);
            $s = $tenant?->settings ?? [];

            if (! filter_var($s['shipping_widget_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return [];
            }

            $out = static::parseDayCutoffs((string) ($s['shipping_day_cutoffs'] ?? ''));
            if ($out !== null) {
                return $out;
            }

            $days = array_values(array_filter(
                array_map('intval', explode(',', (string) ($s['shipping_days'] ?? ''))),
                fn ($d) => $d >= 1 && $d <= 7
            ));
            if ($days === []) {
                return [];
            }

            $cutoff = (string) ($s['shipping_cutoff_time'] ?? '16:00');
            if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $cutoff, $m)) {
                return [];
            }

            $out = array_map(fn ($d) => ['d' => $d, 'h' => (int) $m[1], 'm' => (int) $m[2], 'prev' => false], $days);
            if (filter_var($s['shipping_friday_extension'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $out = array_values(array_filter($out, fn ($e) => $e['d'] !== 6));
                $out[] = ['d' => 6, 'h' => 23, 'm' => 59, 'prev' => true];
            }
            usort($out, fn ($a, $b) => $a['d'] <=> $b['d']);

            return $out;
        });
    }

    /**
     * Kesimi henüz geçmemiş İLK kargo günü.
     * shipping.js `nextShip()` ile birebir aynı mantık.
     *
     * @return array{deadline: CarbonImmutable, offset: int, ship: CarbonImmutable}|null
     */
    public static function nextShip(int $tenantId, ?CarbonImmutable $now = null): ?array
    {
        return static::nextShipFor(static::schedule($tenantId), $now);
    }

    /**
     * nextShip()'in SAF hâli — tenant/cache'e dokunmaz, birim testlenebilir.
     *
     * @param  array<int, array{d: int, h: int, m: int, prev: bool}>  $schedule
     * @return array{deadline: CarbonImmutable, offset: int, ship: CarbonImmutable}|null
     */
    public static function nextShipFor(array $schedule, ?CarbonImmutable $now = null): ?array
    {
        if ($schedule === []) {
            return null;
        }

        $now ??= CarbonImmutable::now(self::TZ);
        $today = $now->startOfDay();

        for ($i = 0; $i < 8; $i++) {
            $ship = $today->addDays($i);
            foreach ($schedule as $e) {
                if ($e['d'] !== (int) $ship->isoWeekday()) {
                    continue;
                }
                $deadline = $ship->subDays($e['prev'] ? 1 : 0)->setTime($e['h'], $e['m']);
                if ($deadline->greaterThan($now)) {
                    return ['deadline' => $deadline, 'offset' => $i, 'ship' => $ship];
                }
            }
        }

        return null;
    }

    /**
     * dispatchLine()'ın testlenebilir kapısı: verilen takvim ve ana göre
     * müşteriye söylenecek kargoya-çıkış cümlesi.
     *
     * @param  array<int, array{d: int, h: int, m: int, prev: bool}>  $schedule
     */
    public static function lineFor(array $schedule, CarbonImmutable $now): ?string
    {
        $ns = static::nextShipFor($schedule, $now);

        return $ns === null ? null : static::dispatchLine($ns, $now);
    }

    /**
     * "Kargom ne zaman gelir / kaç günde gönderirsiniz" sorusunun cevabı.
     * Saate duyarlı: aynı soru 10:00'da ve 14:00'te farklı cevaplanır.
     *
     * @param  string  $transit  taşıma süresi cümlesi (mağazanın kendi metni)
     */
    public static function answer(int $tenantId, ?CarbonImmutable $now = null, string $transit = ''): ?string
    {
        $ns = static::nextShip($tenantId, $now);
        if ($ns === null) {
            return null;
        }

        $now ??= CarbonImmutable::now(self::TZ);
        $transit = static::stripDispatchClaims($transit);
        $transit = $transit !== '' ? $transit : self::DEFAULT_TRANSIT;

        return static::dispatchLine($ns, $now) . ' ' . $transit;
    }

    /**
     * Sipariş DURUM cevaplarının altına eklenen kısa beklenti cümlesi
     * ("Siparişiniz alındı…" + bu). Sipariş zaten verilmiş olduğu için
     * "şu saate kadar verirsen" değil, "kargoya çıkış günü" anlatılır.
     */
    public static function dispatchNote(int $tenantId, ?CarbonImmutable $placedAt = null, ?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now(self::TZ);

        // Kesim, siparişin VERİLDİĞİ ana göre hesaplanır — şu ana göre değil.
        // Saat 09:00'da verilmiş bir sipariş öğleden sonra sorulduğunda
        // "yarın kargoda" demek yanlış olurdu.
        $ns = static::nextShip($tenantId, $placedAt ? $placedAt->setTimezone(self::TZ) : $now);
        if ($ns === null) {
            return null;
        }

        // Söz verilen gün GEÇMİŞSE yeni bir söz verilmez (sipariş gecikmiş
        // demektir; 8 Eyl'de böyle bir müşteriye "1-3 iş günü" denip
        // "3 iş gününü geçti ama" cevabı alınmıştı).
        if ($ns['ship']->startOfDay()->lessThan($now->startOfDay())) {
            return 'Kargoya verildiğinde takip numaranız tarafınıza iletilir.';
        }

        $when = static::whenText($ns['ship'], $now);

        return "Kargo çıkış günümüz {$when}; kargoya verildiğinde takip numaranız tarafınıza iletilir.";
    }

    /**
     * ChatKnowledge korpusuna giren canlı gerçek — LLM yolu da aynı şeyi bilsin.
     * (Hazır cevap yolu handleIntent'ten geçer, ama müşteri soruyu kendi
     * cümlesiyle sorup LLM'e düştüğünde bot yine doğru söylesin.)
     *
     * @return array{title: string, content: string}|null
     */
    public static function fact(int $tenantId, ?CarbonImmutable $now = null): ?array
    {
        $schedule = static::schedule($tenantId);
        if ($schedule === []) {
            return null;
        }

        // ⚠️ KURAL anlatılır, "bugün/yarın" DENMEZ. Korpus 5 dk cache'li
        // (ChatKnowledge::corpus) — kesim saatinde donmuş bir "bugün kargoda"
        // cümlesi 5 dakika boyunca yanlış söz verebilirdi. Müşteriye giden
        // saate duyarlı cümleyi answer() her seferinde yeniden hesaplar.
        $lines = [];
        foreach ($schedule as $e) {
            $day = self::DAY_NAMES[$e['d'] - 1];
            $time = sprintf('%02d:%02d', $e['h'], $e['m']);
            $lines[] = $e['prev']
                ? "{$day} kargosu için bir önceki gün {$time}'e kadar sipariş verilmelidir"
                : "{$day} günü saat {$time}'e kadar verilen siparişler aynı gün kargoya teslim edilir";
        }

        return [
            'title' => 'Kargoya çıkış saati ve kargo günleri (canlı takvim)',
            'content' => 'Kargo takvimimiz: ' . implode('; ', $lines) . '. '
                . 'Kesim saatinden sonra verilen siparişler bir sonraki kargo günü çıkar. '
                . 'Kargo günlerimiz: ' . static::daysText($tenantId) . '. '
                . 'Bu bilgi mağazanın kargo takvimi ayarından gelir ve ürün sayfasındaki "kargoda" rozetiyle aynıdır.',
        ];
    }

    /**
     * Mağazanın hazır cevabındaki KARGOYA ÇIKIŞ iddialarını at.
     *
     * Canlı kesim cümlesiyle çelişmesin diye: metin "siparişler 1-3 iş günü
     * içinde kargoya verilir" derken biz "bugün 11:00'e kadar verilenler bugün
     * kargoda" diyorsak müşteri hangisine inanacağını bilemez (8 Eyl şikâyeti).
     * Taşıma süresini anlatan cümleler (şehre göre, teslimat, takip numarası)
     * KORUNUR — orası taşıyıcının işi ve metnin sahibi mağaza.
     */
    protected static function stripDispatchClaims(string $text): string
    {
        // Yalnız GENİŞ ZAMAN İDDİASI atılır ("kargoya verilir/teslim edilir").
        // "kargoya VERİLDİKTEN sonra teslimat 1-3 iş günü sürer" kalır — o cümle
        // taşıma süresini anlatıyor, kargoya çıkış sözü vermiyor.
        $claim = '/kargoya\s+(veril(ir|iyor|mekte|ecek)|teslim\s+edil(ir|iyor|mekte|ecek)|çık(ar|ıyor|acak))/iu';

        $kept = [];
        foreach (preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [] as $sentence) {
            if (preg_match($claim, $sentence)) {
                continue;
            }
            if (trim($sentence) !== '') {
                $kept[] = trim($sentence);
            }
        }

        return trim(implode(' ', $kept));
    }

    /** "Bugün saat 11:00'e kadar verilen siparişler bugün kargoya teslim edilir." */
    protected static function dispatchLine(array $ns, CarbonImmutable $now): string
    {
        $deadlineDay = static::whenText($ns['deadline'], $now);
        $shipDay = static::whenText($ns['ship'], $now);
        $time = $ns['deadline']->format('H:i');

        // Kesim gece yarısına dayanmışsa ("Cuma 23:59") saat söylemek yanıltıcı olur
        $cut = $time === '23:59'
            ? "{$deadlineDay} gün sonuna kadar"
            : "{$deadlineDay} saat {$time}'e kadar";

        // Kesim ve kargo aynı güne düşüyorsa günü iki kez söyleme
        // ("Yarın saat 11:00'e kadar … yarın kargoya" → "… kargoya").
        $tail = $deadlineDay === $shipDay ? 'kargoya teslim edilir' : "{$shipDay} kargoya teslim edilir";

        return static::upperFirst($cut) . " verilen siparişler {$tail}.";
    }

    /** "bugün" / "yarın" / "Cumartesi" */
    protected static function whenText(CarbonImmutable $day, CarbonImmutable $now): string
    {
        // (int) ŞART: Carbon 3 diffInDays float döndürüyor, `=== 1` hiç tutmuyordu
        // ve "yarın" yerine gün adı yazılıyordu.
        $diff = (int) $now->startOfDay()->diffInDays($day->startOfDay(), false);

        return match (true) {
            $diff <= 0 => 'bugün',
            $diff === 1 => 'yarın',
            default => self::DAY_NAMES[$day->isoWeekday() - 1],
        };
    }

    /** ucfirst() Türkçe'de bozuk (çok baytlı harf) — "çarşamba" → "Çarşamba". */
    protected static function upperFirst(string $t): string
    {
        return mb_strtoupper(mb_substr($t, 0, 1), 'UTF-8') . mb_substr($t, 1);
    }

    /** "Pazartesi-Cuma ve Cumartesi" gibi okunur gün listesi. */
    protected static function daysText(int $tenantId): string
    {
        $days = array_values(array_unique(array_map(fn ($e) => (int) $e['d'], static::schedule($tenantId))));
        sort($days);

        return implode(', ', array_map(fn ($d) => self::DAY_NAMES[$d - 1], $days));
    }

    /** @return array<int, array{d: int, h: int, m: int, prev: bool}>|null */
    protected static function parseDayCutoffs(string $json): ?array
    {
        $raw = json_decode($json, true);
        if (! is_array($raw)) {
            return null;
        }

        $out = [];
        foreach ($raw as $day => $row) {
            $d = (int) $day;
            if ($d < 1 || $d > 7 || ! is_array($row)) {
                continue;
            }
            if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string) ($row['t'] ?? ''), $m)) {
                continue;
            }
            $out[] = ['d' => $d, 'h' => (int) $m[1], 'm' => (int) $m[2], 'prev' => (bool) ($row['prev'] ?? false)];
        }
        if ($out === []) {
            return null;
        }
        usort($out, fn ($a, $b) => $a['d'] <=> $b['d']);

        return $out;
    }

    public static function forget(int $tenantId): void
    {
        Cache::forget("chat:shipping:{$tenantId}");
    }
}
