<?php

namespace App\Services\Marketing\Channels;

use App\Models\Tenant;
use App\Services\Marketing\SendResult;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Crypto\DkimSigner;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;

/**
 * E-posta kanalı — sunucunun yerel MTA'sı (Exim) üzerinden gönderir.
 *
 * cPanel non-root SMTP auth'u kısıtladığı için EmailService::sendReply ile aynı
 * yöntemi kullanır: Symfony 'sendmail://default' transport. Gönderici kimliği
 * (from-address/name, reply-to, unsubscribe) tenant ayarlarından gelir.
 *
 * Pazarlama e-postaları IMAP Sent klasörüne YAZILMAZ (hacim) ve deliverability
 * için List-Unsubscribe başlığı taşır. Kanal varsayılan KAPALI: tenant
 * settings['email_enabled'] === true olmadan isAvailable=false.
 *
 * Mesaj: ['subject'=>..., 'html'=>..., 'body'=>düz metin, 'unsubscribe_url'=>...]
 */
class EmailDriver implements ChannelDriverInterface
{
    public function key(): string
    {
        return 'email';
    }

    /** @return array<string,mixed> */
    protected function settings(int $tenantId): array
    {
        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);

        return ($tenant?->settings ?? []);
    }

    protected function fromAddress(array $s): ?string
    {
        $addr = trim((string) ($s['email_from_address'] ?? ''));

        return filter_var($addr, FILTER_VALIDATE_EMAIL) ? $addr : null;
    }

    public function isAvailable(int $tenantId): bool
    {
        $s = $this->settings($tenantId);

        return ! empty($s['email_enabled']) && $this->fromAddress($s) !== null;
    }

    public function send(int $tenantId, string $to, array $message): SendResult
    {
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return SendResult::fail('Geçersiz alıcı e-posta adresi.', 'exim');
        }

        $s = $this->settings($tenantId);
        $from = $this->fromAddress($s);
        if (! $from) {
            return SendResult::fail('Gönderici e-posta adresi (email_from_address) tanımlı/geçerli değil.', 'exim');
        }

        $subject = trim((string) ($message['subject'] ?? ''));
        if ($subject === '') {
            return SendResult::fail('E-posta konusu boş.', 'exim');
        }

        $html = $message['html'] ?? null;
        $text = trim((string) ($message['body'] ?? ''));
        if (($html === null || trim((string) $html) === '') && $text === '') {
            return SendResult::fail('E-posta içeriği boş.', 'exim');
        }

        try {
            $fromName = trim((string) ($s['email_from_name'] ?? ''));
            $email = (new Email())
                ->from($fromName !== '' ? new Address($from, $fromName) : new Address($from))
                ->to($to)
                ->subject($subject);

            // Bounce/Return-Path = gönderici (ileride ayrı bounce adresi tanımlanabilir)
            $email->sender(new Address($from));

            $replyTo = trim((string) ($s['email_reply_to'] ?? ''));
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $email->replyTo($replyTo);
            }

            if ($html !== null && trim((string) $html) !== '') {
                $email->html($html);
                if ($text !== '') {
                    $email->text($text);
                }
            } else {
                $email->text($text);
            }

            // Deliverability: abonelikten çıkış başlığı (RFC 8058 one-click dahil)
            $unsub = trim((string) ($message['unsubscribe_url'] ?? ($s['email_unsubscribe_url'] ?? '')));
            $headers = $email->getHeaders();
            if ($unsub !== '') {
                $headers->addTextHeader('List-Unsubscribe', "<{$unsub}>");
                $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
            $headers->addTextHeader('X-Yunbi-Channel', 'marketing-email');

            // DKIM imzala (kuruluysa) — deliverability için
            $message = $this->dkimSign($email, $s);

            $transport = Transport::fromDsn('sendmail://default');
            $sent = (new Mailer($transport))->send($message);

            $messageId = $sent?->getMessageId();
            if (! $messageId) {
                $idHeader = $email->getHeaders()->get('Message-ID');
                $messageId = $idHeader ? trim($idHeader->getBodyAsString(), '<> ') : null;
            }

            return SendResult::ok(providerMessageId: $messageId, provider: 'exim', cost: 0.0);
        } catch (\Throwable $e) {
            return SendResult::fail('E-posta gönderilemedi: ' . \Illuminate\Support\Str::limit($e->getMessage(), 120), 'exim');
        }
    }

    /**
     * DKIM imzala. Anahtar kuruluysa imzalı Message döner; değilse e-posta olduğu gibi.
     */
    protected function dkimSign(Email $email, array $s): Message
    {
        $encPrivate = $s['email_dkim_private'] ?? null;
        $domain = trim((string) ($s['email_sending_domain'] ?? ''));
        $selector = trim((string) ($s['email_dkim_selector'] ?? ''));

        if (! $encPrivate || $domain === '' || $selector === '') {
            return $email; // DKIM kurulu değil → imzasız (deliverability düşer)
        }

        try {
            $privatePem = Crypt::decryptString($encPrivate);
            $signer = new DkimSigner($privatePem, $domain, $selector);

            return $signer->sign($email);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('EmailDriver DKIM imzalama başarısız', ['error' => $e->getMessage()]);

            return $email;
        }
    }
}
