<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\EmailMessage;
use App\Services\EmailService;
use Illuminate\Console\Command;
use Symfony\Component\Mime\Email;

class BackfillSentEmails extends Command
{
    protected $signature = 'email:backfill-sent {--channel=16} {--dry-run}';

    protected $description = 'Panelden gönderilmiş ama IMAP Sent klasörüne yazılmamış e-postaları Sent klasörüne kopyalar';

    public function handle(): int
    {
        $channelId = (int) $this->option('channel');
        $dryRun = $this->option('dry-run');

        $channel = Channel::withoutGlobalScopes()->find($channelId);
        if (! $channel) {
            $this->error("Kanal #{$channelId} bulunamadı.");
            return 1;
        }

        $service = new EmailService($channel);
        if (! $service->isAvailable()) {
            $this->error('E-posta servisi kullanılamıyor.');
            return 1;
        }

        $sentFolder = $service->detectSentFolder();
        if (! $sentFolder) {
            $this->error('Sent klasörü bulunamadı.');
            return 1;
        }

        $this->info("Sent klasörü: {$sentFolder}");

        // Sadece panel_reply_ ile başlayan (panelden gönderilen) e-postaları al
        $emails = EmailMessage::withoutGlobalScopes()
            ->where('channel_id', $channelId)
            ->where('direction', 'outgoing')
            ->where('message_id', 'like', 'panel_reply_%')
            ->orderBy('id')
            ->get();

        $this->info("Toplam {$emails->count()} panel e-postası bulundu.");

        if ($dryRun) {
            $this->warn('Dry-run modu: IMAP append yapılmayacak.');
            foreach ($emails as $em) {
                $this->line("  [{$em->id}] {$em->from_email} → {$em->to_email} | {$em->subject}");
            }
            return 0;
        }

        // IMAP bağlantısı aç
        $prefix = $this->getImapPrefix($channel);
        $creds = $channel->credentials ?? [];
        $mailbox = $prefix . $sentFolder;

        $connection = @imap_open($mailbox, $creds['email_address'], $creds['email_password']);
        if (! $connection) {
            $this->error('IMAP bağlantısı kurulamadı: ' . imap_last_error());
            return 1;
        }

        $success = 0;
        $failed = 0;

        foreach ($emails as $em) {
            $rawMessage = $this->buildRawMessage($em);

            // RFC 2822 date from created_at
            $date = $em->created_at->format('d-M-Y H:i:s O');

            $result = @imap_append($connection, $mailbox, $rawMessage, "\\Seen", $date);

            if ($result) {
                $success++;
            } else {
                $failed++;
                $this->warn("  HATA [{$em->id}]: " . imap_last_error());
            }

            // Rate limiting — her 10 e-postada bir durum
            if (($success + $failed) % 10 === 0) {
                $this->info("  İşlendi: {$success} başarılı, {$failed} hata / {$emails->count()}");
            }
        }

        imap_close($connection);

        $this->info("Tamamlandı: {$success} başarılı, {$failed} hata.");

        return 0;
    }

    protected function buildRawMessage(EmailMessage $em): string
    {
        $message = (new Email())
            ->from($em->from_email)
            ->to($em->to_email)
            ->subject($em->subject ?? '(Konu yok)')
            ->date($em->created_at->toDateTimeImmutable())
            ->text($em->body_text ?? '');

        if ($em->in_reply_to) {
            $inReplyTo = str_contains($em->in_reply_to, '<') ? $em->in_reply_to : "<{$em->in_reply_to}>";
            $message->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
        }

        if ($em->references) {
            $message->getHeaders()->addTextHeader('References', $em->references);
        }

        return $message->toString();
    }

    protected function getImapPrefix(Channel $channel): string
    {
        $creds = $channel->credentials ?? [];
        $host = $creds['imap_host'] ?? '';
        $port = $creds['imap_port'] ?? '993';
        $encryption = strtolower($creds['imap_encryption'] ?? 'ssl');

        $flags = '/imap';
        if ($encryption === 'ssl') {
            $flags .= '/ssl';
        } elseif ($encryption === 'tls') {
            $flags .= '/tls';
        } else {
            $flags .= '/notls';
        }

        if (! empty($creds['skip_cert_validation'])) {
            $flags .= '/novalidate-cert';
        }

        return "{{$host}:{$port}{$flags}}";
    }
}
