<?php

namespace App\Support;

/**
 * Kargo takip linklerinin TEK kaynağı.
 *
 * Neden var (8 Eyl 2026): aynı link 4 ayrı yerde ayrı ayrı kuruluyordu
 * (SyncCrmOrders, ClaudeService, ReturnController, OrderStatusNotifier) ve
 * Sürat Kargo takip sayfasını yenileyince yalnız bir tanesi düzeldi. Sürat'ın
 * `www.suratkargo.com.tr/gonderi-takip?code=…` adresi artık **404 veriyor**;
 * bu adres müşteriye chatbot cevabında, sipariş SMS'inde ve panelde
 * gösteriliyordu (orders tablosunda 184.014 kayıt bu biçimdeydi).
 *
 * ⚠️ SÜRAT KARGO GERÇEĞİ (8 Eyl 2026 canlı doğrulama): yeni takip ekranında
 * `?kargotakipno=` parametresi artık kutuya ÖN DOLDURULMUYOR ve sayfada
 * güvenlik kodu (captcha) var. Yani derin link ile doğrudan sonuç ekranına
 * gitmek MÜMKÜN DEĞİL. Bu yüzden müşteriye giden metinlerde takip NUMARASI
 * daima linkin yanında yazılır (kopyalayıp kutuya girecek). Parametre yine de
 * gönderiliyor: zararsız ve Sürat ön doldurmayı geri açarsa kendiliğinden çalışır.
 */
class CargoTracking
{
    /**
     * Kargo firmasının takip adresi.
     *
     * @param  string|null  $company   orders.cargo_company (ör. "Sürat Kargo")
     * @param  string|null  $trackingNo orders.cargo_tracking_no
     * @param  string|null  $orderNo    Sürat, mağaza sipariş referansıyla da sorgu kabul eder
     */
    public static function url(?string $company, ?string $trackingNo, ?string $orderNo = null): ?string
    {
        $lower = mb_strtolower(trim((string) $company));
        $track = trim((string) $trackingNo);
        $order = trim((string) $orderNo);

        if ($lower === '') {
            return null;
        }

        if (str_contains($lower, 'surat') || str_contains($lower, 'sürat')) {
            $code = $track !== '' ? $track : $order;

            return 'https://www.suratkargo.com.tr/KargoTakip/'
                . ($code !== '' ? '?kargotakipno=' . rawurlencode($code) : '');
        }

        if ($track === '') {
            return null;
        }

        return match (true) {
            str_contains($lower, 'yurtici') || str_contains($lower, 'yurtiçi') => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=' . rawurlencode($track),
            str_contains($lower, 'aras') => 'https://kargotakip.araskargo.com.tr/mainpage.aspx?code=' . rawurlencode($track),
            str_contains($lower, 'mng') => 'https://www.mngkargo.com.tr/gonderi-takip?q=' . rawurlencode($track),
            str_contains($lower, 'ptt') => 'https://gonderitakip.ptt.gov.tr/Track/Verify?q=' . rawurlencode($track),
            str_contains($lower, 'trendyol') => 'https://www.trendyolexpress.com/gonderi-takip?barkod=' . rawurlencode($track),
            str_contains($lower, 'hepsijet') => 'https://www.hepsijet.com/gonderi-takibi/' . rawurlencode($track),
            str_contains($lower, 'sendeo') => 'https://www.sendeo.com.tr/tr/takip?ccode=' . rawurlencode($track),
            str_contains($lower, 'ups') => 'https://www.ups.com/track?tracknum=' . rawurlencode($track),
            default => null,
        };
    }

    /**
     * Müşteriye gösterilecek takip cümlesi.
     *
     * Sürat'ta ön doldurma olmadığı için numara CÜMLEDE de geçmek zorunda —
     * "linke bastım, boş sayfa geldi" şikâyetinin (8 Eyl, sohbet #721) sebebi buydu.
     */
    public static function sentence(?string $company, ?string $trackingNo, ?string $orderNo = null): string
    {
        $name = trim((string) $company);
        $track = trim((string) $trackingNo);
        $url = static::url($company, $trackingNo, $orderNo);

        $text = 'Kargonuz yola çıktı' . ($name !== '' ? " ({$name})" : '') . '.';
        if ($track !== '') {
            $text .= " Takip numaranız: {$track}";
        }
        if ($url !== null) {
            $text .= $track !== ''
                ? " — bu numarayı {$url} adresindeki sorgulama kutusuna yazarak kargonuzu takip edebilirsiniz."
                : " Takip: {$url}";
        }

        return $text;
    }
}
