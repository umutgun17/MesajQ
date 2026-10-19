<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailService
{
    protected array $credentials;
    protected Channel $channel;

    public function __construct(Channel $channel)
    {
        $this->channel = $channel;
        $this->credentials = $channel->credentials ?? [];
    }

    public function isAvailable(): bool
    {
        return ! empty($this->credentials['imap_host'])
            && ! empty($this->credentials['email_address'])
            && ! empty($this->credentials['email_password']);
    }

    /**
     * Build IMAP connection prefix: {host:port/flags}
     */
    protected function buildImapPrefix(): string
    {
        $host = $this->credentials['imap_host'] ?? '';
        $port = $this->credentials['imap_port'] ?? '993';
        $encryption = strtolower($this->credentials['imap_encryption'] ?? 'ssl');

        $flags = '/imap';
        if ($encryption === 'ssl') {
            $flags .= '/ssl';
        } elseif ($encryption === 'tls') {
            $flags .= '/tls';
        } else {
            $flags .= '/notls';
        }
        // Sadece açıkça yapılandırıldıysa sertifika doğrulamasını atla
        if (!empty($this->credentials['skip_cert_validation'])) {
            $flags .= '/novalidate-cert';
        }

        return "{{$host}:{$port}{$flags}}";
    }

    /**
     * Open IMAP connection to given folder
     *
     * @return resource|false
     */
    protected function openImapConnection(string $folder = 'INBOX')
    {
        $email = $this->credentials['email_address'] ?? '';
        $password = $this->credentials['email_password'] ?? '';
        $mailbox = $this->buildImapPrefix() . $folder;

        $connection = @imap_open($mailbox, $email, $password);
        if (! $connection) {
            $error = imap_last_error();
            Log::error('EmailService: IMAP connection failed', [
                'folder' => $folder,
                'email' => $email,
                'error' => $error,
            ]);
            return false;
        }

        return $connection;
    }

    /**
     * Fetch new emails via IMAP
     */
    public function fetchNewEmails(int $limit = 50, ?int $days = null): array
    {
        $connection = $this->openImapConnection('INBOX');
        if (! $connection) {
            return [];
        }

        try {
            return $this->readEmails($connection, $limit, $days);
        } finally {
            imap_close($connection);
        }
    }

    /**
     * Detect Sent folder name via IMAP LIST
     * Caches result in channel settings.
     */
    public function detectSentFolder(): ?string
    {
        $settings = $this->channel->settings ?? [];

        // Return cached value if present
        if (! empty($settings['sent_folder'])) {
            return $settings['sent_folder'];
        }

        $email = $this->credentials['email_address'] ?? '';
        $password = $this->credentials['email_password'] ?? '';
        $prefix = $this->buildImapPrefix();

        $connection = @imap_open($prefix . 'INBOX', $email, $password);
        if (! $connection) {
            return null;
        }

        try {
            $folders = @imap_list($connection, $prefix, '*');
            if (! $folders) {
                return null;
            }

            // Common sent folder names (Turkish + English)
            $candidates = [
                'Sent', 'INBOX.Sent', 'Sent Messages', 'INBOX.Sent Messages',
                'Gönderilmiş', 'INBOX.Gönderilmiş', 'Gönderilenler', 'INBOX.Gönderilenler',
                'Sent Items', 'INBOX.Sent Items',
            ];

            foreach ($folders as $folder) {
                // Strip prefix to get folder name
                $folderName = str_replace($prefix, '', $folder);
                // imap_utf7_decode for special chars
                $decoded = imap_utf7_decode($folderName);

                foreach ($candidates as $candidate) {
                    if (strcasecmp($decoded, $candidate) === 0 || strcasecmp($folderName, $candidate) === 0) {
                        // Cache in channel settings
                        $settings['sent_folder'] = $folderName;
                        $this->channel->update(['settings' => $settings]);
                        return $folderName;
                    }
                }
            }

            return null;
        } finally {
            imap_close($connection);
        }
    }

    /**
     * Fetch sent emails from Sent folder
     */
    public function fetchSentEmails(string $folder, int $limit = 50, ?string $sinceDate = null): array
    {
        $connection = $this->openImapConnection($folder);
        if (! $connection) {
            return [];
        }

        try {
            if (! $sinceDate) {
                $sinceDate = now()->subDays(3)->format('d-M-Y');
            }

            $emails = imap_search($connection, "SINCE \"{$sinceDate}\"");
            if (! $emails) {
                return [];
            }

            rsort($emails);
            $emails = array_slice($emails, 0, $limit);

            return $this->parseEmailList($connection, $emails);
        } finally {
            imap_close($connection);
        }
    }

    /**
     * Find existing conversation for a sent email (does NOT create new ones)
     */
    public function findConversationForSentEmail(array $emailData): ?Conversation
    {
        $channelId = $this->channel->id;

        // 1. In-Reply-To header match
        if (! empty($emailData['in_reply_to'])) {
            $parentEmail = EmailMessage::withoutGlobalScopes()
                ->where('channel_id', $channelId)
                ->where('message_id', $emailData['in_reply_to'])
                ->whereNotNull('conversation_id')
                ->first();

            if ($parentEmail) {
                return Conversation::withoutGlobalScopes()->find($parentEmail->conversation_id);
            }
        }

        // 2. References header match
        if (! empty($emailData['references'])) {
            $refIds = preg_split('/\s+/', $emailData['references']);
            $refIds = array_map(fn ($id) => trim($id, '<> '), $refIds);
            $refIds = array_filter($refIds);

            if (! empty($refIds)) {
                $parentEmail = EmailMessage::withoutGlobalScopes()
                    ->where('channel_id', $channelId)
                    ->whereIn('message_id', $refIds)
                    ->whereNotNull('conversation_id')
                    ->orderByDesc('created_at')
                    ->first();

                if ($parentEmail) {
                    return Conversation::withoutGlobalScopes()->find($parentEmail->conversation_id);
                }
            }
        }

        // 3. Subject + recipient fallback (last 7 days)
        $normalizedSubject = $this->normalizeSubject($emailData['subject'] ?? '');
        $toEmail = $emailData['to_email'] ?? '';

        if (! empty($normalizedSubject) && ! empty($toEmail)) {
            $existingEmail = EmailMessage::withoutGlobalScopes()
                ->where('channel_id', $channelId)
                ->where('from_email', $toEmail)
                ->where('ai_category', 'customer_request')
                ->whereNotNull('conversation_id')
                ->where('created_at', '>=', now()->subDays(7))
                ->get()
                ->first(function ($em) use ($normalizedSubject) {
                    return $this->normalizeSubject($em->subject ?? '') === $normalizedSubject;
                });

            if ($existingEmail) {
                return Conversation::withoutGlobalScopes()->find($existingEmail->conversation_id);
            }
        }

        return null;
    }

    /**
     * Read emails from IMAP connection
     */
    protected function readEmails($connection, int $limit, ?int $days = null): array
    {
        // Determine SINCE date: explicit --days > last_synced_at > default 3 days
        if ($days) {
            $sinceDate = now()->subDays($days)->format('d-M-Y');
        } elseif ($this->channel->last_synced_at) {
            $sinceDate = $this->channel->last_synced_at->format('d-M-Y');
        } else {
            $sinceDate = now()->subDays(3)->format('d-M-Y');
        }

        $emails = imap_search($connection, "SINCE \"{$sinceDate}\"");

        if (! $emails) {
            return [];
        }

        // Sort by newest first and limit
        rsort($emails);
        $emails = array_slice($emails, 0, $limit);

        return $this->parseEmailList($connection, $emails);
    }

    /**
     * Parse a list of IMAP email numbers into structured data
     */
    protected function parseEmailList($connection, array $emailNums): array
    {
        $results = [];

        foreach ($emailNums as $emailNum) {
            try {
                $header = imap_headerinfo($connection, $emailNum);
                $structure = imap_fetchstructure($connection, $emailNum);

                if (! $header) {
                    continue;
                }

                // Parse Message-ID
                $messageId = isset($header->message_id)
                    ? trim($header->message_id, '<> ')
                    : null;

                if (! $messageId) {
                    continue;
                }

                // Parse In-Reply-To
                $inReplyTo = null;
                $rawHeaders = imap_fetchheader($connection, $emailNum);
                if (preg_match('/^In-Reply-To:\s*(.+)$/mi', $rawHeaders, $m)) {
                    $inReplyTo = trim($m[1], '<> ');
                }

                // Parse References
                $references = null;
                if (preg_match('/^References:\s*(.+?)(?=^\S)/msi', $rawHeaders . "\nX:", $m)) {
                    $references = trim($m[1]);
                }

                // Parse From
                $fromEmail = '';
                $fromName = '';
                if (! empty($header->from)) {
                    $from = $header->from[0];
                    $fromEmail = ($from->mailbox ?? '') . '@' . ($from->host ?? '');
                    $fromName = isset($from->personal)
                        ? $this->decodeMimeHeader($from->personal)
                        : '';
                }

                // Parse To
                $toEmail = '';
                if (! empty($header->to)) {
                    $to = $header->to[0];
                    $toEmail = ($to->mailbox ?? '') . '@' . ($to->host ?? '');
                }

                // Parse Subject
                $subject = isset($header->subject)
                    ? $this->decodeMimeHeader($header->subject)
                    : '(Konu yok)';

                // Parse Date
                $date = isset($header->date)
                    ? date('Y-m-d H:i:s', strtotime($header->date))
                    : now()->toDateTimeString();

                // Parse Body
                $bodyText = $this->getBody($connection, $emailNum, $structure, 'text/plain');
                $bodyHtml = $this->getBody($connection, $emailNum, $structure, 'text/html');

                // If no plain text, strip HTML
                if (empty($bodyText) && ! empty($bodyHtml)) {
                    $bodyText = strip_tags($bodyHtml);
                }

                $results[] = [
                    'message_id' => $messageId,
                    'in_reply_to' => $inReplyTo,
                    'references' => $references,
                    'from_email' => strtolower(trim($fromEmail)),
                    'from_name' => $fromName,
                    'to_email' => strtolower(trim($toEmail)),
                    'subject' => $subject,
                    'body_text' => $bodyText,
                    'body_html' => $bodyHtml,
                    'date' => $date,
                ];
            } catch (\Exception $e) {
                Log::warning('EmailService: Failed to parse email', [
                    'email_num' => $emailNum,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Get email body by MIME type
     */
    protected function getBody($connection, int $emailNum, $structure, string $mimeType): string
    {
        $parts = $this->flattenParts($structure);

        foreach ($parts as $partInfo) {
            [$partNum, $part] = $partInfo;

            $type = strtolower(($part->subtype ?? ''));
            $primaryType = $part->type ?? 0;

            $fullType = match ($primaryType) {
                0 => 'text/' . $type,
                default => 'other/' . $type,
            };

            if ($fullType !== $mimeType) {
                continue;
            }

            $body = $partNum === '1' && ! isset($structure->parts)
                ? imap_body($connection, $emailNum, FT_PEEK)
                : imap_fetchbody($connection, $emailNum, $partNum, FT_PEEK);

            // Decode
            $encoding = $part->encoding ?? 0;
            $body = match ($encoding) {
                3 => base64_decode($body),       // BASE64
                4 => quoted_printable_decode($body), // QUOTED-PRINTABLE
                default => $body,
            };

            // Handle charset
            $charset = 'UTF-8';
            if (! empty($part->parameters)) {
                foreach ($part->parameters as $param) {
                    if (strtolower($param->attribute) === 'charset') {
                        $charset = $param->value;
                        break;
                    }
                }
            }

            if (strtoupper($charset) !== 'UTF-8') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
                if ($converted !== false) {
                    $body = $converted;
                }
            }

            return trim($body);
        }

        return '';
    }

    /**
     * Flatten MIME part tree into indexed list
     */
    protected function flattenParts($structure, string $prefix = ''): array
    {
        $parts = [];

        if (isset($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $partNum = $prefix === '' ? (string) ($index + 1) : $prefix . '.' . ($index + 1);
                $parts[] = [$partNum, $part];

                if (isset($part->parts)) {
                    $parts = array_merge($parts, $this->flattenParts($part, $partNum));
                }
            }
        } else {
            $parts[] = [$prefix ?: '1', $structure];
        }

        return $parts;
    }

    /**
     * Classify email using rule-based logic
     */
    public function classifyEmail(string $subject, string $bodyText, string $fromEmail = ''): string
    {
        $fromEmail = strtolower(trim($fromEmail));
        $domain = substr($fromEmail, strpos($fromEmail, '@') + 1);
        $localPart = substr($fromEmail, 0, strpos($fromEmail, '@'));
        $subjectLower = mb_strtolower($subject);
        $bodyLower = mb_strtolower(Str::limit($bodyText, 500));

        // 1. Bounce (mailer-daemon / postmaster)
        if (str_contains($fromEmail, 'mailer-daemon') || str_contains($fromEmail, 'postmaster')) {
            return 'notification';
        }

        // 2. Auto-reply detection
        if ($this->isAutoReply($subjectLower, $bodyLower, $fromEmail)) {
            return 'auto_reply';
        }

        // 3. Notification domains (system/business notifications)
        if ($this->isNotificationSender($domain, $localPart)) {
            return 'notification';
        }

        // 4. Spam domains (B2B marketing, ads)
        if ($this->isSpamSender($domain, $fromEmail, $subjectLower)) {
            return 'spam';
        }

        // 5. Everything else from individual email → customer_request
        return 'customer_request';
    }

    /**
     * Detect auto-reply / out-of-office emails
     */
    protected function isAutoReply(string $subject, string $body, string $fromEmail): bool
    {
        $autoReplySubjects = [
            'otomatik yanıt', 'otomatik cevap', 'automatic reply',
            'auto-reply', 'autoreply', 'out of office', 'ofis dışı',
            'tatildeyim', 'dönüş yapacağım',
        ];

        foreach ($autoReplySubjects as $keyword) {
            if (str_contains($subject, $keyword)) {
                return true;
            }
        }

        // noreply/no-reply senders with generic content
        if (str_contains($fromEmail, 'noreply') || str_contains($fromEmail, 'no-reply')) {
            return false; // These go to notification check instead
        }

        return false;
    }

    /**
     * Check if sender is a known notification source
     */
    protected function isNotificationSender(string $domain, string $localPart): bool
    {
        // Known notification domains
        $notificationDomains = [
            // Marketplace / B2B partners
            'pazarama.com', 'flo.com.tr', 'lcwaikiki.com',
            // Government / official
            'gelirler.gov.tr', 'info.gelirler.gov.tr', 'ito.org.tr',
            'bilgi.ito.org.tr', 'tim.org.tr', 'e.tim.org.tr',
            // System / platform notifications
            'sikayetvar.com', 'foriba.com', 'cloud.foriba.com',
            'sovos.com', 'updates.sovos.com',
            'accounts.google.com',
            // B2B supplier notifications
            'izmiratesplastik.com.tr',
        ];

        foreach ($notificationDomains as $nd) {
            if ($domain === $nd || str_ends_with($domain, '.' . $nd)) {
                return true;
            }
        }

        // noreply / no-reply / bilgi / bulten senders
        if (in_array($localPart, ['noreply', 'no-reply', 'bilgi', 'bulten', 'mutabakat'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if sender is spam (B2B marketing, ads, unsolicited)
     */
    protected function isSpamSender(string $domain, string $fromEmail, string $subject): bool
    {
        // Known spam domains
        $spamDomains = [
            'mytenerji.com', 'messeturkey.com.tr', 'parlakgroup.com.tr',
            'mediaprime.com.tr', 'mobwebify.com', 'navlungo.com',
            'revotas.com', 'e.revotas.com', 'progeslojistik.com',
            'dtslojistik.net', 'gunsanambalaj.com',
            'duyuru.pinaronline.com', 'pinaronline.com',
        ];

        foreach ($spamDomains as $sd) {
            if ($domain === $sd || str_ends_with($domain, '.' . $sd)) {
                return true;
            }
        }

        // Spam subject keywords (B2B marketing patterns)
        $spamKeywords = [
            'fuar', 'ticaret heyeti', 'ihale', 'webinar',
            'seo support', 'digital marketing', 'cooperation follow',
            'teşvikli', 'enerji tedarik', 'lojistik hizmet',
        ];

        foreach ($spamKeywords as $keyword) {
            if (str_contains($subject, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send reply via local sendmail (Exim)
     *
     * Uses the server's local MTA instead of authenticated SMTP,
     * because cPanel restricts SMTP auth for non-root users.
     *
     * @return array{success: bool, error: string|null, message_id: string|null}
     */
    public function sendReply(string $to, string $subject, string $body, ?string $inReplyTo = null, ?string $references = null): array
    {
        $email = $this->credentials['email_address'] ?? '';

        if (empty($email)) {
            return ['success' => false, 'error' => 'Gönderici e-posta adresi eksik', 'message_id' => null];
        }

        // Add "Re: " prefix if not present (yalnız cevaplarda — yeni/ilk e-postada dokunma)
        if ($inReplyTo && ! Str::startsWith($subject, 'Re: ')) {
            $subject = 'Re: ' . $subject;
        }

        $usedFallback = false;
        $fallbackReason = null;

        try {
            $usingSmtp = $this->hasSmtpCredentials();

            $message = (new \Symfony\Component\Mime\Email())
                ->from($email)
                ->to($to)
                ->subject($subject)
                ->text($body);

            // Set threading headers
            if ($inReplyTo) {
                $message->getHeaders()->addTextHeader('In-Reply-To', "<{$inReplyTo}>");
            }
            if ($references) {
                $ref = $references;
                if ($inReplyTo) {
                    $ref .= " <{$inReplyTo}>";
                }
                $message->getHeaders()->addTextHeader('References', $ref);
            }

            // 1) Firmanın KENDİ SMTP'si — geçici auth/bağlantı reddi için 3 denemeye
            // kadar TAZE bağlantıyla tekrar dene (dış paylaşımlı mail sunucuları
            // yoğunlukta auth'u geçici reddedebiliyor).
            $sentMessage = null;
            $lastError = null;
            $attempts = $usingSmtp ? 3 : 1;

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    $mailer = new \Symfony\Component\Mailer\Mailer($this->buildTransport());
                    $sentMessage = $mailer->send($message);
                    $lastError = null;
                    break;
                } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
                    $lastError = $e;
                    if ($attempt < $attempts) {
                        sleep($attempt);
                    }
                }
            }

            // 2) Hâlâ olmadıysa: cevabı KAYBETME, yerel sendmail'e düş — AMA bu
            // teslimat RİSKLİDİR: mail hub sunucusunun IP'sinden çıkar, firmanın
            // SPF kaydında o IP yoktur ve DKIM imzası atılamaz → Gmail/Outlook
            // reddedebilir (4 Eyl 2026 olayı: 10 Ağu-4 Eyl arası 48 cevap böyle
            // düştü). Bu yüzden çağıran tarafa 'fallback' bayrağı döndürülür;
            // MessageController mesajı "teslim şüpheli" işaretler ve konuşmayı
            // yeniden açar. Sebep genelde cPanel dış-SMTP kısıtlamasının web
            // kullanıcısının bağlantısını yerel exim'e yönlendirmesidir —
            // kalıcı muafiyet: /var/cpanel/smtpmailgidonly/conf.yaml (uid 1017)
            // + /usr/local/sbin/yunbi-smtp-exempt.sh.
            if ($lastError !== null) {
                if (! $usingSmtp) {
                    throw $lastError;
                }
                $usedFallback = true;
                $fallbackReason = Str::limit($lastError->getMessage(), 150);

                Log::error('EmailService: firma SMTP sunucusuna ulaşılamadı, yerel sendmail ile gönderiliyor (TESLİM RİSKLİ — SPF/DKIM yok)', [
                    'channel_id' => $this->channel->id,
                    'smtp_host' => $this->credentials['smtp_host'] ?? null,
                    'attempts' => $attempts,
                    'error' => $fallbackReason,
                ]);

                $fallback = new \Symfony\Component\Mailer\Mailer(
                    \Symfony\Component\Mailer\Transport::fromDsn('sendmail://default')
                );
                $sentMessage = $fallback->send($message);
            }

            // Extract real Message-ID from sent message
            $sentMessageId = null;
            if ($sentMessage) {
                $sentMessageId = $sentMessage->getMessageId();
            }
            // Fallback: read from the Email object headers
            if (! $sentMessageId) {
                $idHeader = $message->getHeaders()->get('Message-ID');
                if ($idHeader) {
                    $sentMessageId = trim($idHeader->getBodyAsString(), '<> ');
                }
            }

            // Append to IMAP Sent folder so it appears in webmail
            $this->appendToSentFolder($message);

            return [
                'success' => true,
                'error' => null,
                'message_id' => $sentMessageId,
                'fallback' => $usedFallback,
                'fallback_reason' => $fallbackReason,
            ];
        } catch (\Exception $e) {
            Log::error('EmailService: Send failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'E-posta gönderilemedi: ' . Str::limit($e->getMessage(), 100),
                'message_id' => null,
                'fallback' => $usedFallback,
                'fallback_reason' => $fallbackReason,
            ];
        }
    }

    /**
     * Giden posta taşıyıcısı: kanalın KENDİ SMTP sunucusu.
     *
     * Cevaplar firmanın kendi sunucusundan çıkmalı — hub sunucusundan
     * sendmail ile göndermek SPF'e takılıyordu (alan adlarının SPF kaydında
     * hub IP'si yok → softfail → spam riski). SMTP bilgisi tanımlı değilse
     * eski davranışa (yerel sendmail) düşülür.
     *
     * Sertifika doğrulaması skip_cert_validation ile kapatılabilir; paylaşımlı
     * hosting'de sunucu sertifikasının CN'i alan adıyla uyuşmuyor (IMAP tarafı
     * da aynı sebeple novalidate-cert kullanıyor).
     */
    protected function hasSmtpCredentials(): bool
    {
        return ! empty($this->credentials['smtp_host'])
            && ! empty($this->credentials['email_address'])
            && ! empty($this->credentials['email_password']);
    }

    protected function buildTransport(): \Symfony\Component\Mailer\Transport\TransportInterface
    {
        if (! $this->hasSmtpCredentials()) {
            return \Symfony\Component\Mailer\Transport::fromDsn('sendmail://default');
        }

        $host = $this->credentials['smtp_host'];
        $user = $this->credentials['email_address'];
        $pass = $this->credentials['email_password'];

        $port = (int) ($this->credentials['smtp_port'] ?? 587);
        $encryption = strtolower((string) ($this->credentials['smtp_encryption'] ?? 'tls'));
        $implicitTls = $encryption === 'ssl' || $port === 465;

        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport($host, $port, $implicitTls);

        if (! empty($this->credentials['skip_cert_validation'])) {
            $stream = $transport->getStream();
            if ($stream instanceof \Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream) {
                $stream->setStreamOptions(['ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ]]);
            }
        }

        $transport->setUsername($user);
        $transport->setPassword($pass);

        return $transport;
    }

    /**
     * Giden posta yolunun sağlık kontrolü: firmanın SMTP sunucusuna bağlanıp
     * AUTH dener (mail GÖNDERMEZ). `email:health-check` komutu bunu kullanır.
     *
     * NEDEN VAR: 10 Ağu–4 Eyl 2026 arası cPanel'in dış-SMTP kısıtlaması web
     * kullanıcısının bağlantısını yerel exim'e yönlendirdi; kod sessizce
     * sendmail'e düştü ve 48 cevap Gmail/Outlook tarafından reddedildi. Kimse
     * fark etmedi çünkü kontrol eden bir şey yoktu. Artık her gün kontrol edilir.
     *
     * @return array{ok:bool,using_smtp:bool,host:?string,ms:int,error:?string}
     */
    public function testSmtpConnection(): array
    {
        $host = $this->credentials['smtp_host'] ?? null;

        if (! $this->hasSmtpCredentials()) {
            return [
                'ok' => false,
                'using_smtp' => false,
                'host' => $host,
                'ms' => 0,
                'error' => 'Kanalda SMTP bilgisi tanımlı değil (gönderim yerel sendmail ile yapılır — SPF/DKIM riski).',
            ];
        }

        $started = microtime(true);

        try {
            $transport = $this->buildTransport();
            $transport->start();
            $transport->stop();

            return [
                'ok' => true,
                'using_smtp' => true,
                'host' => $host,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'using_smtp' => true,
                'host' => $host,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'error' => Str::limit($e->getMessage(), 200),
            ];
        }
    }

    /**
     * Append a sent email to the IMAP Sent folder so it appears in webmail.
     */
    protected function appendToSentFolder(\Symfony\Component\Mime\Email $message): void
    {
        try {
            $sentFolder = $this->detectSentFolder();
            if (! $sentFolder) {
                Log::warning('EmailService: Sent folder not found, skipping IMAP append');
                return;
            }

            $connection = $this->openImapConnection($sentFolder);
            if (! $connection) {
                return;
            }

            $rawMessage = $message->toString();
            $mailbox = $this->buildImapPrefix() . $sentFolder;

            $result = @imap_append($connection, $mailbox, $rawMessage, "\\Seen");
            if (! $result) {
                Log::warning('EmailService: IMAP append to Sent failed', [
                    'folder' => $sentFolder,
                    'error' => imap_last_error(),
                ]);
            }

            imap_close($connection);
        } catch (\Exception $e) {
            Log::warning('EmailService: IMAP append exception', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find or create conversation for email thread
     */
    public function findOrCreateConversation(array $emailData, Channel $channel): Conversation
    {
        $tenantId = $channel->tenant_id;

        // 1. Thread matching via In-Reply-To header
        if (! empty($emailData['in_reply_to'])) {
            $parentEmail = EmailMessage::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->where('message_id', $emailData['in_reply_to'])
                ->whereNotNull('conversation_id')
                ->first();

            if ($parentEmail) {
                return Conversation::withoutGlobalScopes()->find($parentEmail->conversation_id);
            }
        }

        // 2. Thread matching via References header
        if (! empty($emailData['references'])) {
            $refIds = preg_split('/\s+/', $emailData['references']);
            $refIds = array_map(fn ($id) => trim($id, '<> '), $refIds);
            $refIds = array_filter($refIds);

            if (! empty($refIds)) {
                $parentEmail = EmailMessage::withoutGlobalScopes()
                    ->where('channel_id', $channel->id)
                    ->whereIn('message_id', $refIds)
                    ->whereNotNull('conversation_id')
                    ->orderByDesc('created_at')
                    ->first();

                if ($parentEmail) {
                    return Conversation::withoutGlobalScopes()->find($parentEmail->conversation_id);
                }
            }
        }

        // 3. Fallback: same subject + sender within last 7 days
        $normalizedSubject = $this->normalizeSubject($emailData['subject'] ?? '');
        if (! empty($normalizedSubject) && ! empty($emailData['from_email'])) {
            $existingEmail = EmailMessage::withoutGlobalScopes()
                ->where('channel_id', $channel->id)
                ->where('from_email', $emailData['from_email'])
                ->where('ai_category', 'customer_request')
                ->whereNotNull('conversation_id')
                ->where('created_at', '>=', now()->subDays(7))
                ->get()
                ->first(function ($em) use ($normalizedSubject) {
                    return $this->normalizeSubject($em->subject ?? '') === $normalizedSubject;
                });

            if ($existingEmail) {
                return Conversation::withoutGlobalScopes()->find($existingEmail->conversation_id);
            }
        }

        // 4. Create new conversation
        $customer = $this->findOrCreateCustomer(
            $emailData['from_email'],
            $emailData['from_name'] ?? '',
            $tenantId
        );

        return Conversation::create([
            'tenant_id' => $tenantId,
            'channel_id' => $channel->id,
            'customer_id' => $customer->id,
            'type' => 'message',
            'status' => 'open',
            'is_read' => false,
            'unread_count' => 1,
            'last_message_at' => now(),
            'platform_conversation_id' => 'email_' . Str::random(16),
            'metadata' => [
                'email_subject' => $emailData['subject'] ?? null,
                'email_from' => $emailData['from_email'],
            ],
        ]);
    }

    /**
     * Find or create customer by email
     */
    public function findOrCreateCustomer(string $email, string $name, int $tenantId): Customer
    {
        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('email', $email)
            ->first();

        if ($customer) {
            return $customer;
        }

        // Parse name into first/last
        $parts = explode(' ', trim($name), 2);
        $firstName = $parts[0] ?: $email;
        $lastName = $parts[1] ?? null;

        return Customer::create([
            'tenant_id' => $tenantId,
            'channel_id' => $this->channel->id,
            'name' => $firstName,
            'surname' => $lastName,
            'email' => $email,
            'platform_id' => $email,
            'platform_type' => 'email',
        ]);
    }

    /**
     * Decode MIME encoded header (handles multi-token =?UTF-8?B?...?= sequences)
     */
    protected function decodeMimeHeader(string $header): string
    {
        $parts = imap_mime_header_decode($header);
        if (! $parts || $parts === false) {
            return $header;
        }

        $decoded = '';
        foreach ($parts as $part) {
            $charset = $part->charset ?? 'default';
            $text = $part->text ?? '';

            if ($charset !== 'default' && strtoupper($charset) !== 'UTF-8') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
                if ($converted !== false) {
                    $text = $converted;
                }
            }

            $decoded .= $text;
        }

        return trim($decoded) ?: $header;
    }

    /**
     * Normalize subject for matching (remove Re:/Fwd: prefixes, trim whitespace)
     */
    protected function normalizeSubject(string $subject): string
    {
        // Remove Re:, Fwd:, FW:, YNT:, İLT: prefixes (case-insensitive, multiple)
        $normalized = preg_replace('/^(Re|Fwd|FW|YNT|İLT)\s*:\s*/iu', '', $subject);
        while ($normalized !== $subject) {
            $subject = $normalized;
            $normalized = preg_replace('/^(Re|Fwd|FW|YNT|İLT)\s*:\s*/iu', '', $subject);
        }

        return mb_strtolower(trim($normalized));
    }
}
