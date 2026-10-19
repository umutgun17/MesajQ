<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Pazarlama e-postası gönderilmeyecek adres (unsubscribe / bounce / complaint).
 */
class EmailSuppression extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'email', 'reason', 'source'];

    /** Bir adres bu tenant için bastırılmış mı? (CLI/public — global scope yok) */
    public static function isSuppressed(int $tenantId, string $email): bool
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('email', mb_strtolower(trim($email)))
            ->exists();
    }

    /** Adresi listeye ekle (varsa dokunma). */
    public static function suppress(int $tenantId, string $email, string $reason = 'unsubscribe', ?string $source = null): void
    {
        static::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'email' => mb_strtolower(trim($email))],
            ['reason' => $reason, 'source' => $source],
        );
    }
}
