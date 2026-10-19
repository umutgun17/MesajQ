<?php

namespace App\Support;

/**
 * Bozuk kodlanmış (çift UTF-8 / "mojibake") Türkçe metni onarır.
 *
 * Ticimax bazı üye/sipariş adlarını, UTF-8 baytları tek baytlık kodlama
 * (Windows-1252 / ISO-8859-1 karışık) sanılarak yeniden kodlanmış hâlde
 * döndürüyor: "Ã‡aÄŸla GÃ¼ler" → "Çağla Güler", "Ä\u{9E}" (görünmez) → "Ğ".
 * Her karakter tek bayta geri çevrilir; sonuç geçerli UTF-8 ise kabul edilir,
 * değilse metne dokunulmaz. Temiz metin hiç işlenmez.
 */
class TurkishText
{
    private const MARKER = '/[ÃÄÅ][\x{0080}-\x{02FF}\x{2010}-\x{203A}\x{20AC}\x{2122}]/u';

    /** Windows-1252'nin 0x80–0x9F aralığındaki özel karakterleri → bayt */
    private const CP1252 = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86,
        0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
        0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95,
        0x2013 => 0x96, 0x2014 => 0x97, 0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B,
        0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    public static function fix(?string $s): ?string
    {
        if ($s === null || $s === '' || ! preg_match(self::MARKER, $s)) {
            return $s;
        }

        // En fazla iki tur (bazı kayıtlar iki kez bozulmuş olabilir)
        for ($i = 0; $i < 2 && preg_match(self::MARKER, $s); $i++) {
            $bytes = '';
            foreach (mb_str_split($s) as $ch) {
                $cp = mb_ord($ch, 'UTF-8');
                if ($cp <= 0xFF) {
                    $bytes .= chr($cp);
                } elseif (isset(self::CP1252[$cp])) {
                    $bytes .= chr(self::CP1252[$cp]);
                } else {
                    return $s; // tek bayta inmeyen karakter → bu metin mojibake değil
                }
            }
            if (! mb_check_encoding($bytes, 'UTF-8')) {
                return $s;
            }
            $s = $bytes;
        }

        return $s;
    }
}
