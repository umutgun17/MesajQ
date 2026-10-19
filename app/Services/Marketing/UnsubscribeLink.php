<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\URL;

/**
 * Alıcıya özel, imzalı (tahrif edilemez) abonelikten-çıkış linki üretir.
 * E-posta List-Unsubscribe başlığında ve gövdedeki "Abonelikten çık" linkinde kullanılır.
 */
class UnsubscribeLink
{
    public static function for(int $tenantId, string $email): string
    {
        return URL::signedRoute('marketing.unsubscribe', [
            'tenant' => $tenantId,
            'email' => mb_strtolower(trim($email)),
        ]);
    }
}
