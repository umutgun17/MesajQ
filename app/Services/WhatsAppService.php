<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected string $apiUrl;

    public function __construct()
    {
        $this->apiUrl = config('meta.graph_api_url');
    }

    /**
     * Meta'nın kullanıcı adı (username) akışında telefonunu gizleyen müşteri
     * "TR.1108540038525267" gibi bir **işletmeye özel kullanıcı kimliği (BSUID)**
     * ile gelir (webhook: contacts[].user_id / messages[].from_user_id). Bu bir
     * telefon numarası DEĞİLDİR ama Temmuz 2026'dan beri Cloud API ona da gönderim
     * yapıyor — `to` yerine `recipient` alanıyla (bkz. recipientFields()).
     * 10 Eyl'e kadar bu kimlik `to` alanında denendiği için #131026 dönüyordu.
     * Kaynak: developers.facebook.com/documentation/business-messaging/whatsapp/business-scoped-user-ids/
     */
    public static function isBsuid(?string $waId): bool
    {
        return (bool) preg_match('/^[A-Z]{2}\.[A-Za-z0-9]{1,128}$/', (string) $waId);
    }

    /** Salt telefon numarası mı? (10–15 hane) */
    public static function isPhoneAddress(?string $waId): bool
    {
        $digits = preg_replace('/\D+/', '', (string) $waId);

        return $digits !== '' && $digits === (string) $waId && strlen($digits) >= 10 && strlen($digits) <= 15;
    }

    /**
     * Bu adrese Cloud API ile mesaj gönderilebilir mi? Telefon numarası VEYA
     * BSUID. (9 Eyl olayı: kurtarılan 8 konuşmaya yazılan 6 cevabın hiçbiri
     * ulaşmamıştı; artık BSUID de gönderim adresi.)
     */
    public static function isSendableAddress(?string $waId): bool
    {
        return self::isPhoneAddress($waId) || self::isBsuid($waId);
    }

    /**
     * /messages isteğinin alıcı alanları: telefonda `to`, BSUID'de `recipient`
     * (+ recipient_type=individual). İkisi birden verilirse Meta `to`ya öncelik
     * verir; o yüzden BSUID'de `to` HİÇ gönderilmez.
     * Not: BSUID'ye tek-tık/sıfır-tık/kopya-kod DOĞRULAMA şablonları gitmez (#131062).
     */
    protected function recipientFields(string $to): array
    {
        if (self::isBsuid($to)) {
            return ['recipient_type' => 'individual', 'recipient' => $to];
        }

        return ['to' => $to];
    }

    /**
     * Parse WhatsApp webhook entry — extracts messages and statuses.
     * Returns normalized message array or null.
     */
    public function parseWebhookEntry(array $entry): ?array
    {
        $changes = $entry['changes'] ?? [];

        foreach ($changes as $change) {
            if (($change['field'] ?? '') !== 'messages') {
                continue;
            }

            $value = $change['value'] ?? [];
            $metadata = $value['metadata'] ?? [];
            $phoneNumberId = $metadata['phone_number_id'] ?? null;

            // Process incoming messages
            $messages = $value['messages'] ?? [];
            if (empty($messages)) {
                return null;
            }

            $msg = $messages[0];
            $contact = ($value['contacts'][0] ?? []);

            $text = '';
            $mediaType = null;

            switch ($msg['type'] ?? 'text') {
                case 'text':
                    $text = $msg['text']['body'] ?? '';
                    break;
                case 'image':
                    $text = $msg['image']['caption'] ?? '[Fotoğraf]';
                    $mediaType = 'image';
                    break;
                case 'video':
                    $text = $msg['video']['caption'] ?? '[Video]';
                    $mediaType = 'video';
                    break;
                case 'audio':
                    $text = '[Ses Mesajı]';
                    $mediaType = 'audio';
                    break;
                case 'document':
                    $text = $msg['document']['caption'] ?? '[Dosya: ' . ($msg['document']['filename'] ?? 'belge') . ']';
                    $mediaType = 'document';
                    break;
                case 'sticker':
                    $text = '[Çıkartma]';
                    $mediaType = 'sticker';
                    break;
                case 'location':
                    $lat = $msg['location']['latitude'] ?? '';
                    $lng = $msg['location']['longitude'] ?? '';
                    $text = "[Konum: {$lat}, {$lng}]";
                    $mediaType = 'location';
                    break;
                case 'contacts':
                    $text = '[Kişi Paylaşımı]';
                    $mediaType = 'contacts';
                    break;
                case 'button':
                    // Şablon hızlı-yanıt butonu (ör. "Evet")
                    $text = $msg['button']['text'] ?? $msg['button']['payload'] ?? '[Buton Yanıtı]';
                    break;
                case 'interactive':
                    $interactive = $msg['interactive'] ?? [];
                    $text = $interactive['button_reply']['title']
                        ?? $interactive['list_reply']['title']
                        ?? '[Etkileşimli Yanıt]';
                    break;
                case 'reaction':
                    // Skip reactions for now
                    return null;
                default:
                    // Meta bazı türleri (tek seferlik medya, anket, video not,
                    // düzenlenmiş mesaj vb.) API'ye "unsupported" olarak iletir
                    // ve içeriği hiç göndermez.
                    $text = '[Görüntülenemeyen mesaj (tek seferlik medya/anket vb.) — WhatsApp bu türü iletmiyor, müşteriden tekrar göndermesini isteyin]';
                    $unsupportedType = $msg['type'] ?? 'unknown';
                    Log::warning('WhatsAppService: unsupported message type', [
                        'type'    => $unsupportedType,
                        'from'    => $msg['from'] ?? null,
                        'wamid'   => $msg['id'] ?? null,
                        'errors'  => $msg['errors'] ?? null,
                        'message' => $msg,
                    ]);
                    break;
            }

            // Build media metadata if applicable
            $metadata_extra = [];
            if (isset($unsupportedType)) {
                $metadata_extra['raw_type'] = $unsupportedType;
                if (!empty($msg['errors'])) {
                    $metadata_extra['wa_errors'] = $msg['errors'];
                }
            }
            if ($mediaType && $mediaType !== 'location' && $mediaType !== 'contacts') {
                $mediaData = $msg[$mediaType] ?? [];
                $metadata_extra = [
                    'media_type' => $mediaType,
                    'media_id' => $mediaData['id'] ?? null,
                    'mime_type' => $mediaData['mime_type'] ?? null,
                ];
            }

            // Gönderen kimliği: klasik akışta $msg['from'] telefon numarasıdır.
            // Meta'nın kullanıcı adı / telefonsuz kimlik akışında 'from' boş
            // gelebiliyor; mesajı SESSİZCE DÜŞÜRMEK yerine mevcut alternatif
            // kimlik alanlarına düşüyoruz (bkz. ham payload logu aşağıda).
            $waId = $msg['from']
                ?? $contact['wa_id']
                ?? $contact['user_id']
                ?? ($msg['from_user_id'] ?? null)
                ?? ($msg['sender']['user_id'] ?? null)
                ?? ($msg['identity']['user_id'] ?? null)
                ?? null;

            if (empty($msg['from'])) {
                Log::warning('WhatsAppService: gelen mesajda "from" yok — ham payload', [
                    'phone_number_id' => $phoneNumberId,
                    'resolved_wa_id'  => $waId,
                    'wamid'           => $msg['id'] ?? null,
                    'raw_value'       => $value,
                ]);
            }

            return [
                'phone_number_id' => $phoneNumberId,
                'wa_id'           => $waId,
                'message_id'      => $msg['id'] ?? null,
                'contact_name'    => $contact['profile']['name'] ?? null,
                // Kullanıcı adı akışı: telefonunu gizleyen müşterinin @kullanıcı adı
                // ve işletmeye özel kimliği (BSUID) — müşteri kartına işlenir.
                'username'        => $contact['profile']['username'] ?? null,
                'user_id'         => $contact['user_id'] ?? ($msg['from_user_id'] ?? null),
                'text'            => $text,
                'type'            => $msg['type'] ?? 'text',
                'timestamp'       => $msg['timestamp'] ?? now()->timestamp,
                'metadata'        => $metadata_extra ?: null,
            ];
        }

        return null;
    }

    /**
     * Parse outbound-message status updates (sent/delivered/read/failed)
     * from a webhook entry. SADECE gelen statüleri okur — müşteriye asla
     * okundu bilgisi (mavi tik) göndermez.
     */
    public function parseWebhookStatuses(array $entry): array
    {
        $out = [];

        foreach (($entry['changes'] ?? []) as $change) {
            if (($change['field'] ?? '') !== 'messages') {
                continue;
            }
            foreach (($change['value']['statuses'] ?? []) as $st) {
                if (empty($st['id']) || empty($st['status'])) {
                    continue;
                }
                $out[] = [
                    'message_id'   => $st['id'],
                    'status'       => $st['status'],
                    'timestamp'    => $st['timestamp'] ?? null,
                    'recipient_id' => $st['recipient_id'] ?? null,
                    'errors'       => $st['errors'] ?? null,
                ];
            }
        }

        return $out;
    }

    /**
     * Send a text message via WhatsApp Cloud API.
     */
    public function sendMessage(string $to, string $text, string $phoneNumberId, string $accessToken): array
    {
        // Zaman aşımı ŞART: varsayılanla Graph API takıldığında istek 500 ile
        // düşüyor, mesaj panelde "gitmiş" görünüyordu. Otomatik retry YOK —
        // Cloud API'de idempotency anahtarı olmadığı için çift mesaj riski var.
        try {
            $response = Http::withToken($accessToken)
                ->connectTimeout(10)
                ->timeout(25)
                ->post("{$this->apiUrl}/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    ...$this->recipientFields($to),
                    'type'              => 'text',
                    'text'              => ['body' => $text],
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('WhatsAppService::sendMessage bağlantı hatası', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'transient' => true, 'error' => ['error' => ['message' => 'WhatsApp sunucusuna ulaşılamadı (zaman aşımı) — mesaj gönderilemedi, lütfen tekrar deneyin.']]];
        }

        if ($response->failed()) {
            Log::error('WhatsAppService::sendMessage failed', [
                'to'     => $to,
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        }

        $messageId = $response->json('messages.0.id');

        return [
            'success'    => true,
            'message_id' => $messageId,
        ];
    }

    /**
     * Upload a media file to the WhatsApp Cloud API (POST /{pnid}/media).
     * Dönen media_id Meta tarafında ~30 gün geçerlidir; sendMedia ile kullanılır.
     */
    public function uploadMedia(string $filePath, string $mimeType, string $fileName, string $phoneNumberId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->timeout(60)
            ->attach('file', file_get_contents($filePath), $fileName, ['Content-Type' => $mimeType])
            ->post("{$this->apiUrl}/{$phoneNumberId}/media", [
                'messaging_product' => 'whatsapp',
                'type'              => $mimeType,
            ]);

        if ($response->failed() || ! $response->json('id')) {
            Log::error('WhatsAppService::uploadMedia failed', [
                'mime'   => $mimeType,
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        }

        return ['success' => true, 'media_id' => $response->json('id')];
    }

    /**
     * Send a previously uploaded media (image/video/document) message.
     * Caption yalnız image/video/document'ta desteklenir (Cloud API sınırı 1024 karakter).
     */
    public function sendMedia(string $to, string $mediaType, string $mediaId, ?string $caption, ?string $filename, string $phoneNumberId, string $accessToken): array
    {
        $media = ['id' => $mediaId];
        if ($caption !== null && $caption !== '' && in_array($mediaType, ['image', 'video', 'document'])) {
            $media['caption'] = mb_substr($caption, 0, 1024);
        }
        if ($mediaType === 'document' && $filename) {
            $media['filename'] = $filename;
        }

        try {
            $response = Http::withToken($accessToken)
                ->connectTimeout(10)
                ->timeout(25)
                ->post("{$this->apiUrl}/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    ...$this->recipientFields($to),
                    'type'              => $mediaType,
                    $mediaType          => $media,
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('WhatsAppService::sendMedia bağlantı hatası', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'transient' => true, 'error' => ['error' => ['message' => 'WhatsApp sunucusuna ulaşılamadı (zaman aşımı) — medya gönderilemedi, lütfen tekrar deneyin.']]];
        }

        if ($response->failed()) {
            Log::error('WhatsAppService::sendMedia failed', [
                'to'       => $to,
                'media_id' => $mediaId,
                'type'     => $mediaType,
                'status'   => $response->status(),
                'body'     => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        }

        return [
            'success'    => true,
            'message_id' => $response->json('messages.0.id'),
        ];
    }

    /**
     * Send a template message via WhatsApp Cloud API.
     * Required for proactive sends outside the 24h customer-service window.
     *
     * @param  array  $components  Optional template components (body/header params, buttons).
     */
    public function sendTemplate(string $to, string $templateName, string $languageCode, array $components, string $phoneNumberId, string $accessToken): array
    {
        $template = [
            'name'     => $templateName,
            'language' => ['code' => $languageCode],
        ];

        if (! empty($components)) {
            $template['components'] = $components;
        }

        try {
            $response = Http::withToken($accessToken)
                ->connectTimeout(10)
                ->timeout(25)
                ->post("{$this->apiUrl}/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    ...$this->recipientFields($to),
                    'type'              => 'template',
                    'template'          => $template,
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('WhatsAppService::sendTemplate bağlantı hatası', [
                'to'       => $to,
                'template' => $templateName,
                'error'    => $e->getMessage(),
            ]);

            return ['success' => false, 'transient' => true, 'error' => ['error' => ['message' => 'WhatsApp sunucusuna ulaşılamadı (zaman aşımı) — şablon gönderilemedi.']]];
        }

        if ($response->failed()) {
            Log::error('WhatsAppService::sendTemplate failed', [
                'to'       => $to,
                'template' => $templateName,
                'status'   => $response->status(),
                'body'     => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        }

        return [
            'success'    => true,
            'message_id' => $response->json('messages.0.id'),
        ];
    }

    /**
     * Mark a message as read (blue ticks).
     */
    public function markAsRead(string $messageId, string $phoneNumberId, string $accessToken): bool
    {
        $response = Http::withToken($accessToken)
            ->post("{$this->apiUrl}/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'status'            => 'read',
                'message_id'        => $messageId,
            ]);

        if ($response->failed()) {
            Log::warning('WhatsAppService::markAsRead failed', [
                'message_id' => $messageId,
                'status'     => $response->status(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Get media URL from media ID.
     */
    public function getMediaUrl(string $mediaId, string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)
            ->get("{$this->apiUrl}/{$mediaId}");

        if ($response->failed()) {
            return null;
        }

        return $response->json('url');
    }

    /**
     * List message templates on a WABA (for campaign template picker).
     */
    public function listTemplates(string $wabaId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get("{$this->apiUrl}/{$wabaId}/message_templates", [
                'fields' => 'name,status,category,language,components',
                'limit'  => 100,
            ]);

        if ($response->failed()) {
            Log::warning('WhatsAppService::listTemplates failed', [
                'waba'   => $wabaId,
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json(), 'data' => []];
        }

        return ['success' => true, 'data' => $response->json('data') ?? []];
    }

    /**
     * Create a message template on a WABA (goes to Meta review).
     */
    public function createTemplate(string $wabaId, string $accessToken, array $payload): array
    {
        $response = Http::withToken($accessToken)
            ->post("{$this->apiUrl}/{$wabaId}/message_templates", $payload);

        if ($response->failed()) {
            Log::warning('WhatsAppService::createTemplate failed', [
                'waba'   => $wabaId,
                'name'   => $payload['name'] ?? null,
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            return ['success' => false, 'error' => $response->json()];
        }

        return ['success' => true, 'data' => $response->json()];
    }

    /**
     * Phone number quality/limit info (messaging_limit_tier may be omitted by Meta).
     */
    public function phoneInfo(string $phoneNumberId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get("{$this->apiUrl}/{$phoneNumberId}", [
                'fields' => 'quality_rating,messaging_limit_tier,throughput,name_status,display_phone_number',
            ]);

        return $response->failed() ? [] : ($response->json() ?? []);
    }

    /**
     * Get access token from channel credentials (fallback to config).
     */
    public function getAccessToken(array $credentials): ?string
    {
        return $credentials['access_token']
            ?? config('meta.whatsapp_access_token');
    }

    /**
     * Get phone number ID from channel settings (fallback to config).
     */
    public function getPhoneNumberId(array $settings): ?string
    {
        return $settings['phone_number_id']
            ?? config('meta.whatsapp_phone_number_id');
    }
}
