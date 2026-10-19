<?php

namespace App\Services\Marketing;

/**
 * WhatsApp için TR telefon normalizasyonu. Geçerli sonuç DAİMA 905XXXXXXXXX (12 hane).
 * Geçersiz/eksik numarada null döner — asla "olduğu gibi" gönderilmez.
 */
class WaPhone
{
    public static function normalize(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if ($d === '') {
            return null;
        }
        // Uluslararası önek varyantları
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        // Baştaki yerel 0'lar: 05XX..., 0090...
        while (str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        if (strlen($d) === 10 && str_starts_with($d, '5')) {
            $d = '90'.$d;
        }
        if (strlen($d) === 12 && str_starts_with($d, '905')) {
            return $d;
        }

        return null;
    }

    /** Eşleştirme anahtarı: son 10 hane (5XXXXXXXXX). */
    public static function last10(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (strlen($d) < 10) {
            return null;
        }
        $ten = substr($d, -10);

        return str_starts_with($ten, '5') ? $ten : null;
    }
}
