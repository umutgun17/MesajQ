<?php

namespace App\Services\Marketing;

use Illuminate\Support\Carbon;

/**
 * Pazarlama gönderim saat aralığı (Türkiye saati). Tek pencere: bu saatler ARASINDA
 * gönderilir, dışında gönderilmez. settings:
 *   marketing_quiet_enabled (bool), marketing_send_start ("09:00"), marketing_send_end ("21:00").
 *
 * Pencere gece yarısını aşabilir (start > end → ör. 21:00–09:00 ters anlam olmaz; biz
 * "gönderilebilir aralık" olarak yorumlarız, wrap destekli).
 */
class SendWindow
{
    public static function enabled(array $s): bool
    {
        return ! empty($s['marketing_quiet_enabled']);
    }

    public static function start(array $s): string
    {
        return self::norm($s['marketing_send_start'] ?? '09:00');
    }

    public static function end(array $s): string
    {
        return self::norm($s['marketing_send_end'] ?? '21:00');
    }

    /** Şu an (veya verilen an) gönderime açık mı? Pencere kapalıysa her zaman açık sayılır. */
    public static function isOpen(array $s, ?Carbon $at = null): bool
    {
        if (! self::enabled($s)) {
            return true;
        }
        $now = ($at ?? now())->copy()->setTimezone('Europe/Istanbul')->format('H:i');
        $start = self::start($s);
        $end = self::end($s);

        if ($start === $end) {
            return true; // 24 saat
        }
        if ($start < $end) {
            return $now >= $start && $now < $end;           // normal gündüz aralığı
        }

        return $now >= $start || $now < $end;               // gece yarısını aşan aralık
    }

    /**
     * Pencere açıkken, kapanacağı bir sonraki anı döndürür (TR). Pencere kapalı/24 saat
     * ise null. Gece-akıllı gönderim için: "bu akşam pencere ne zaman kapanıyor?"
     */
    public static function nextClose(array $s, ?Carbon $at = null): ?Carbon
    {
        if (! self::enabled($s)) {
            return null;
        }
        $start = self::start($s);
        $end = self::end($s);
        if ($start === $end) {
            return null; // 24 saat — kapanmaz
        }
        $now = ($at ?? now())->copy()->setTimezone('Europe/Istanbul');
        [$eh, $em] = array_map('intval', explode(':', $end));
        $close = $now->copy()->setTime($eh, $em, 0);
        if ($close->lessThanOrEqualTo($now)) {
            $close->addDay();
        }

        return $close;
    }

    /** Verilen andan SONRAKİ ilk açılış anını döndürür (TR). */
    public static function nextOpenAfter(array $s, Carbon $after): Carbon
    {
        $after = $after->copy()->setTimezone('Europe/Istanbul');
        [$h, $m] = array_map('intval', explode(':', self::start($s)));
        $open = $after->copy()->setTime($h, $m, 0);
        if ($open->lessThan($after)) {
            $open->addDay();
        }

        return $open;
    }

    /** Pencere kapalıysa, bir sonraki açılış anını döndürür (TR), açıksa null. */
    public static function nextOpen(array $s, ?Carbon $at = null): ?Carbon
    {
        if (self::isOpen($s, $at)) {
            return null;
        }
        $now = ($at ?? now())->copy()->setTimezone('Europe/Istanbul');
        [$h, $m] = array_map('intval', explode(':', self::start($s)));
        $open = $now->copy()->setTime($h, $m, 0);
        if ($open->lessThanOrEqualTo($now)) {
            $open->addDay();
        }

        return $open;
    }

    protected static function norm(string $v): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $v) ? $v : '09:00';
    }
}
