<?php

namespace App\Services\Chat;

use Carbon\CarbonImmutable;

/**
 * AI Chatbot — mesai saatleri. Temsilciye devirde müşteriye DOĞRU beklenti
 * verilir: mesai içindeyse "kısa süre içinde", dışındaysa "ilk mesai saatinde".
 *
 * Ayar tenants.settings'te: chat_hours_enabled (bool) + chat_hours (JSON).
 * chat_hours formatı — 7 elemanlı dizi, 0=Pazartesi ... 6=Pazar:
 *   [{"open":"09:00","close":"18:00"}, ..., null]   (null/kapalı gün = null)
 * Saat dilimi: Europe/Istanbul (config('app.timezone') değil — mağaza saati).
 */
class ChatBusinessHours
{
    public const TZ = 'Europe/Istanbul';

    /** 0=Pazartesi ... 6=Pazar (kullanıcı kararı 4 Eyl: hafta içi 09-18 + Cmt 09-14) */
    public const DEFAULT_HOURS = [
        ['open' => '09:00', 'close' => '18:00'],
        ['open' => '09:00', 'close' => '18:00'],
        ['open' => '09:00', 'close' => '18:00'],
        ['open' => '09:00', 'close' => '18:00'],
        ['open' => '09:00', 'close' => '18:00'],
        ['open' => '09:00', 'close' => '14:00'],
        null,
    ];

    public const DAY_NAMES = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];

    /** @param array<int, array|null> $hours */
    public static function isOpen(array $hours, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now(self::TZ);
        $slot = $hours[static::dayIndex($now)] ?? null;

        if (! is_array($slot) || empty($slot['open']) || empty($slot['close'])) {
            return false;
        }

        $cur = $now->format('H:i');

        return $cur >= $slot['open'] && $cur < $slot['close'];
    }

    /**
     * Mesai dışındayken "ne zaman dönülecek" metni.
     * Örn: "yarın 09:00", "Pazartesi 09:00", "bugün 09:00".
     */
    public static function nextOpenText(array $hours, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now(self::TZ);

        for ($i = 0; $i < 8; $i++) {
            $day = $now->addDays($i);
            $slot = $hours[static::dayIndex($day)] ?? null;
            if (! is_array($slot) || empty($slot['open'])) {
                continue;
            }

            // Bugünse ve açılış saati henüz gelmediyse bugün geçerli
            if ($i === 0 && $now->format('H:i') >= $slot['open']) {
                continue;
            }

            $prefix = match ($i) {
                0 => 'bugün',
                1 => 'yarın',
                default => self::DAY_NAMES[static::dayIndex($day)],
            };

            return $prefix . ' saat ' . $slot['open'];
        }

        return 'ilk iş günü';
    }

    /** Panelde/mesajda gösterilecek özet: "Hafta içi 09:00-18:00, Cumartesi 09:00-14:00" */
    public static function summary(array $hours): string
    {
        $groups = [];
        foreach ($hours as $i => $slot) {
            $key = is_array($slot) && ! empty($slot['open'])
                ? $slot['open'] . '-' . $slot['close']
                : null;
            if ($key === null) {
                continue;
            }
            $groups[$key][] = $i;
        }

        $parts = [];
        foreach ($groups as $range => $days) {
            $parts[] = static::dayRangeLabel($days) . ' ' . $range;
        }

        return $parts ? implode(', ', $parts) : 'belirtilmedi';
    }

    /** @param array<int, int> $days */
    protected static function dayRangeLabel(array $days): string
    {
        sort($days);

        if ($days === [0, 1, 2, 3, 4]) {
            return 'Hafta içi';
        }
        if (count($days) === 7) {
            return 'Her gün';
        }
        if (count($days) === 1) {
            return self::DAY_NAMES[$days[0]];
        }

        // Ardışıksa "Pazartesi-Cuma", değilse virgüllü liste
        $consecutive = true;
        for ($i = 1; $i < count($days); $i++) {
            if ($days[$i] !== $days[$i - 1] + 1) {
                $consecutive = false;
                break;
            }
        }

        return $consecutive
            ? self::DAY_NAMES[$days[0]] . '-' . self::DAY_NAMES[end($days)]
            : implode(', ', array_map(fn ($d) => self::DAY_NAMES[$d], $days));
    }

    /** Carbon 0=Pazar döner; bizim dizimiz 0=Pazartesi. */
    protected static function dayIndex(CarbonImmutable $d): int
    {
        return ((int) $d->dayOfWeek + 6) % 7;
    }

    /**
     * Panelden gelen ham değeri güvenli 7 elemanlı diziye çevirir.
     *
     * @param  mixed  $raw
     * @return array<int, array|null>
     */
    public static function sanitize($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return self::DEFAULT_HOURS;
        }

        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $slot = $raw[$i] ?? null;
            $open = is_array($slot) ? trim((string) ($slot['open'] ?? '')) : '';
            $close = is_array($slot) ? trim((string) ($slot['close'] ?? '')) : '';

            $valid = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $open)
                && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $close)
                && $close > $open;

            $out[$i] = $valid ? ['open' => $open, 'close' => $close] : null;
        }

        return $out;
    }
}
