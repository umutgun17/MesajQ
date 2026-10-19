<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kanal-bağımsız gönderim bastırması. Şikayet eden / "numaramı silin" diyen /
 * yanlış-numara vakalarındaki alıcılara HİÇBİR pazarlama/işlem mesajı gitmez
 * (UnifiedSender skip_gates'i bile atlamaz).
 */
class MarketingSuppression extends Model
{
    protected $fillable = ['tenant_id', 'channel', 'identifier', 'reason', 'source'];

    /**
     * Alıcıyı kanala göre normalize et: e-posta → lower(trim), telefon → son 10 hane.
     */
    public static function normalize(string $channel, string $to): string
    {
        if ($channel === 'email' || str_contains($to, '@')) {
            return mb_strtolower(trim($to));
        }
        $digits = preg_replace('/\D/', '', $to);

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    public static function isSuppressed(int $tenantId, string $channel, string $to): bool
    {
        $id = self::normalize($channel, $to);
        if ($id === '') {
            return false;
        }

        return self::query()
            ->where('tenant_id', $tenantId)
            ->where('identifier', $id)
            ->whereIn('channel', [$channel, 'all'])
            ->exists();
    }

    /** Bastırma ekle (idempotent). */
    public static function add(int $tenantId, string $channel, string $to, ?string $reason = null, ?string $source = null): self
    {
        return self::firstOrCreate(
            ['tenant_id' => $tenantId, 'channel' => $channel, 'identifier' => self::normalize($channel, $to)],
            ['reason' => $reason, 'source' => $source]
        );
    }
}
