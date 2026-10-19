<?php

namespace App\Services\Marketing\Channels;

use App\Services\Marketing\SendResult;

/**
 * Tüm pazarlama kanalları (sms/email/whatsapp/push) bu arayüzü uygular.
 * UnifiedSender yalnızca bu arayüzü tanır — kanal eklemek = yeni driver.
 */
interface ChannelDriverInterface
{
    /** Kanal anahtarı: sms | email | whatsapp | push */
    public function key(): string;

    /** Bu tenant için kanal yapılandırılmış ve gönderime hazır mı? */
    public function isAvailable(int $tenantId): bool;

    /**
     * Mesajı gönder.
     *
     * @param array $message ['body'=>..., 'subject'=>..., 'header'=>..., ...]
     */
    public function send(int $tenantId, string $to, array $message): SendResult;
}
