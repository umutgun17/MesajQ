<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiResponseLog;
use App\Models\AiTrainingData;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\HepsiburadaService;
use App\Services\MetaService;
use App\Services\TicimaxService;
use App\Services\TrendyolService;
use App\Services\EmailService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


class MessageController extends Controller
{
    public function __construct(
        protected MetaService $metaService,
        protected WhatsAppService $whatsAppService,
    ) {}

    public function index(Conversation $conversation): JsonResponse
    {
        $messages = $conversation->messages()
            ->with('sender:id,name,avatar')
            ->orderBy('created_at')
            ->paginate(50);

        return response()->json($messages);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            // Ekli gönderimde metin = başlık (caption, WA sınırı 1024); eksiz gönderimde zorunlu
            'content' => $request->hasFile('attachment')
                ? 'nullable|string|max:1024'
                : 'required|string|max:5000',
            'attachment' => 'sometimes|file|max:16384|mimetypes:image/jpeg,image/png,video/mp4,application/pdf',
            'is_ai_generated' => 'sometimes|boolean',
            'ai_original_content' => 'sometimes|nullable|string',
            'handling_time_seconds' => 'sometimes|nullable|integer|min:0|max:86400',
        ]);

        $channelType = $conversation->channel->type ?? null;

        // Dosya eki: şimdilik yalnız WhatsApp (Cloud API media upload → media_id ile gönderim)
        $waMedia = null;
        if ($request->hasFile('attachment')) {
            if ($channelType !== 'whatsapp') {
                return response()->json(['message' => 'Dosya eki şu an yalnız WhatsApp konuşmalarında gönderilebilir.'], 422);
            }
            $waMedia = $this->uploadWhatsAppMedia($request, $conversation);
            if ($waMedia instanceof JsonResponse) {
                return $waMedia;
            }
        }

        $content = trim((string) ($validated['content'] ?? ''));
        if ($content === '' && $waMedia) {
            $content = match ($waMedia['media_type']) {
                'image' => '[Fotoğraf]',
                'video' => '[Video]',
                default => '[Dosya: ' . ($waMedia['filename'] ?? 'belge') . ']',
            };
        }

        $message = $conversation->messages()->create([
            'sender_type' => 'agent',
            'sender_id' => $request->user()->id,
            'content' => $content,
            'is_ai_generated' => $validated['is_ai_generated'] ?? false,
            'ai_original_content' => $validated['ai_original_content'] ?? null,
            'handling_time_seconds' => $validated['handling_time_seconds'] ?? null,
            'metadata' => $waMedia ? [
                'media_type' => $waMedia['media_type'],
                'media_id'   => $waMedia['media_id'],
                'mime_type'  => $waMedia['mime_type'],
                'filename'   => $waMedia['filename'],
            ] : null,
        ]);

        // Ticimax: statüyü mesaj doğrulandıktan sonra güncelle (sendToTicimax içinde)
        $skipStatusUpdate = ($channelType === 'ticimax-ticket');

        $conversation->update([
            'last_message_at' => now(),
            'status' => $skipStatusUpdate ? $conversation->status : 'resolved',
            'ai_suggestion' => null,
            'ai_suggestion_confidence' => null,
        ]);

        // Send to external platform
        $sendError = null;
        if (in_array($channelType, ['instagram-message', 'facebook-message'])) {
            $sendError = $this->sendToMeta($conversation, $message, $content);
        } elseif ($channelType === 'whatsapp') {
            // 9 Eyl: düz metin hataları da artık yutulmuyor. Eskiden yalnız
            // medyalı gönderimde hata dönüyordu; Graph API zaman aşımına
            // düştüğünde mesaj panelde "gitmiş" görünüp müşteriye hiç
            // ulaşmıyordu (sessiz kayıp). Hata dönerse konuşma 'open'a geri
            // alınır, mesaja delivery_error rozeti işlenir ve personel görür.
            $sendError = $this->sendToWhatsApp($conversation, $message, $content, $waMedia);
        } elseif ($channelType === 'trendyol-product') {
            $sendError = $this->sendToTrendyol($conversation, $message, $content);
        } elseif ($channelType === 'hepsiburada-product') {
            // 14 Eyl: pazaryeri redleri (Hepsiburada içerik filtresi, link/telefon
            // yasağı) artık yutulmuyor — mesaja delivery_error rozeti + composer'da
            // hata bandı; konuşma 'open'a döner. Ağu-Eyl'de 20 cevap sessizce kaybolmuştu.
            $sendError = $this->sendToHepsiburada($conversation, $message, $content);
        } elseif ($channelType === 'ticimax-ticket') {
            $sendError = $this->sendToTicimax($conversation, $message, $content);
        } elseif ($channelType === 'email') {
            $sendError = $this->sendEmailReply($conversation, $message, $content);
        } elseif ($channelType === 'webchat') {
            $sendError = $this->sendToWebchat($conversation, $message, $content);
        } elseif ($channelType === 'sms') {
            $sendError = $this->sendToSms($conversation, $message, $content);
        }

        // If send failed (Meta veya e-posta): revert status to 'open' and save error in message metadata
        // E-posta için de gerekli — aksi halde SMTP hatası kaybolur, konuşma yanlışlıkla 'resolved' kalır
        // ve personel başarısız gönderiyi göremez/yeniden deneyemez.
        if ($sendError && in_array($channelType, ['instagram-message', 'facebook-message', 'email', 'sms', 'whatsapp', 'webchat', 'trendyol-product', 'hepsiburada-product'])) {
            $conversation->update(['status' => 'open']);

            $metadata = $message->metadata ?? [];
            $metadata['delivery_error'] = $sendError;
            $message->update(['metadata' => $metadata]);
        }

        // Update AiResponseLog if this was an AI-generated message
        $validated['content'] = $content; // ekli gönderimde content boş gelebilir — yer tutucu/caption ile doldurulmuş hali
        if ($validated['is_ai_generated'] ?? false) {
            $this->updateAiResponseLog($conversation, $validated);
        }

        // Auto-learn: create training data from agent response
        $this->autoLearn($conversation, $validated);

        $message->load('sender:id,name,avatar');

        $response = $message->toArray();
        if ($sendError) {
            $response['send_error'] = $sendError;
        }

        return response()->json($response, 201);
    }

    /**
     * Webchat (AI chatbot handoff): dış platform yok — personel cevabı chat_messages'a
     * kopyalanır, widget 5 sn polling ile alır. Bkz docs/ai-chatbot.md.
     */
    /**
     * Site sohbetinden devredilen konuşmaya personel cevabı.
     *
     * İKİ KANAL (4 Eyl kararı):
     *  - MESAİ İÇİ: cevap yalnız widget'a yazılır. Bot müşteriye "birazdan
     *    temsilcimiz bu sohbete bağlanacak, sayfayı açık tutun" dediği için
     *    SMS GÖNDERİLMEZ (gereksiz maliyet + tekrar).
     *  - MESAİ DIŞI: müşteri sayfada değildir; bot "SMS ile ileteceğiz" demiştir
     *    → cevap SMS olarak da gider. Personel isterse `send_sms` bayrağıyla
     *    mesai içinde de zorlayabilir.
     *
     * @return string|null  gönderim hatası (varsa)
     */
    protected function sendToWebchat(Conversation $conversation, Message $message, string $content): ?string
    {
        try {
            $session = \App\Models\ChatSession::withoutGlobalScopes()
                ->where('tenant_id', $conversation->tenant_id)
                ->where('conversation_id', $conversation->id)
                ->first();

            if ($session) {
                \App\Models\ChatMessage::withoutGlobalScopes()->create([
                    'tenant_id' => $conversation->tenant_id,
                    'chat_session_id' => $session->id,
                    'role' => 'agent',
                    'content' => $content,
                    'payload' => ['message_id' => $message->id],
                ]);

                $session->forceFill(['last_activity_at' => now()])->save();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('sendToWebchat failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->maybeSmsForWebchat($conversation, $message, $content);
    }

    /** Webchat cevabının SMS olarak da gidip gitmeyeceğine karar verir ve gönderir. */
    protected function maybeSmsForWebchat(Conversation $conversation, Message $message, string $content): ?string
    {
        $phone = $conversation->customer?->phone ?: $conversation->customer?->platform_id;
        // force_sms: müşteri bu konuşmaya SMS ile yazmış (bkz sms:poll-inbox) →
        // sayfada değil, cevap mesai içinde bile SMS'ten gitmeli.
        $forced = request()->boolean('send_sms')
            || (bool) (($conversation->metadata ?? [])['force_sms'] ?? false);

        $settings = \App\Services\Chat\TenantChatSettings::get($conversation->tenant_id);
        $openNow = ! $settings['hours_enabled'] || \App\Services\Chat\ChatBusinessHours::isOpen(
            $settings['hours'],
            \Carbon\CarbonImmutable::now(\App\Services\Chat\ChatBusinessHours::TZ)
        );

        // Mesai içi kuralı YALNIZ müşteri hâlâ sayfadaysa geçerli. Widget 5 sn'de
        // bir yokluyor; son hareketi 15 dk'yı geçtiyse sekme kapanmıştır ve
        // cevabı kimse görmez. (5 Eyl: gece devredilen 3 müşteriye sabah cevap
        // yazıldı, üçü de sayfadan çoktan ayrılmıştı.)
        if (! $forced && $openNow && $this->webchatVisitorPresent($conversation)) {
            return null; // mesai içi ve müşteri sayfada: widget yeter
        }
        if (! $phone) {
            // Mesai dışı ama numara yok → personel bilsin (müşteriye ulaşılamaz)
            $this->tagMessage($message, ['sms_skipped' => 'no_phone']);

            return null;
        }

        // TEK YÖNLÜ: SMS gönderici başlığımız harf tabanlı ("TONNY BLACK") ve
        // müşteriye yanıt hattı VERİLMEZ (5 Eyl kullanıcı kararı) — dönüş
        // beklenmiyor, cevap kanalı webchat'tir.
        $result = app(\App\Services\Marketing\Channels\SmsDriver::class)
            ->send($conversation->tenant_id, $phone, ['body' => $content]);

        $this->tagMessage($message, $result->success
            ? ['sms_sent' => true, 'sms_to' => $phone]
            : ['sms_sent' => false, 'sms_error' => $result->error]);

        return $result->success ? null : ($result->error ?? 'SMS gönderilemedi');
    }

    /** Ziyaretçi sohbet penceresini hâlâ açık tutuyor mu? (widget 5 sn'de bir yoklar) */
    protected function webchatVisitorPresent(Conversation $conversation): bool
    {
        $sessionId = ($conversation->metadata ?? [])['chat_session_id'] ?? null;
        if (! $sessionId) {
            return false; // webchat oturumu yoksa sayfada da değildir
        }

        // last_activity_at DEĞİL: onu personelin kendi cevabı da tazeliyor ve
        // ziyaretçi her zaman "sayfada" görünürdü.
        $lastSeen = \App\Models\ChatSession::withoutGlobalScopes()->whereKey($sessionId)->value('last_seen_at');

        return $lastSeen !== null && \Carbon\CarbonImmutable::parse($lastSeen)->gt(now()->subMinutes(15));
    }

    /** @param array<string, mixed> $fields */
    protected function tagMessage(Message $message, array $fields): void
    {
        $meta = $message->metadata ?? [];
        $message->update(['metadata' => array_merge($meta, $fields)]);
    }

    protected function sendToMeta(Conversation $conversation, Message $message, string $content): ?string
    {
        $customer = $conversation->customer;
        $channel  = $conversation->channel;

        if (! $customer?->platform_id || ! $channel) {
            return null;
        }

        $credentials = $channel->credentials ?? [];
        $pageAccessToken = $this->metaService->getPageAccessToken($credentials);

        if (! $pageAccessToken) {
            Log::warning('MessageController: No page access token for channel', [
                'channel_id' => $channel->id,
            ]);
            return 'No page access token';
        }

        $channelType = $channel->type;
        $settings = $channel->settings ?? [];
        $igAccountId = $settings['page_id'] ?? null; // IG Business Account ID

        $result = $this->metaService->sendMessage(
            $customer->platform_id,
            $content,
            $pageAccessToken,
            $channelType,
            $igAccountId
        );

        if ($result['success'] && isset($result['message_id'])) {
            $updateData = ['platform_message_id' => $result['message_id']];

            // Mark if sent via HUMAN_AGENT tag (outside 24h window)
            if (! empty($result['used_human_agent'])) {
                $metadata = $message->metadata ?? [];
                $metadata['used_human_agent'] = true;
                $updateData['metadata'] = $metadata;
            }

            $message->update($updateData);
            return null;
        }

        $errorCode = $result['error']['error']['code'] ?? null;

        // 8 Eki 2026: #551 + sohbet bir yorumdan doğmuşsa (kullanıcı sayfaya hiç
        // yazmamış) PSID'ye gönderim imkânsız → yoruma "özel yanıt" ile ulaş.
        if ($channelType === 'facebook-message' && (int) $errorCode === 551) {
            $commentId = $this->metaService->resolveCommentOrigin($conversation);

            if ($commentId) {
                $pr = $this->metaService->sendPrivateReply($commentId, $content, $pageAccessToken);

                if ($pr['success']) {
                    $metadata = $message->metadata ?? [];
                    $metadata['private_reply_comment_id'] = $commentId;
                    $message->update([
                        'platform_message_id' => $pr['message_id'],
                        'metadata' => $metadata,
                    ]);

                    return null;
                }

                $prMsg = $pr['error']['error']['message'] ?? 'özel yanıt gönderilemedi';

                return "Bu kişi sayfaya mesaj yazmadı, yalnız yorum yaptı; yoruma özel yanıt da gidemedi: {$prMsg} "
                    . '(yorum başına tek özel yanıt hakkı vardır ve 7 günde dolar — müşteri cevap yazana kadar yeni mesaj gönderilemez).';
            }

            return "Bu kişi sayfaya hiç mesaj yazmadı (Meta #551). Facebook, yorumdan açılan bu sohbete yalnız "
                . 'yoruma "özel yanıt" ile mesaj gönderilmesine izin verir; eşleşen bir yorum bulunamadı.';
        }

        $errorMsg = $result['error']['error']['message'] ?? 'Platform send failed';
        return $errorMsg;
    }

    protected function sendToWhatsApp(Conversation $conversation, Message $message, string $content, ?array $media = null): ?string
    {
        $customer = $conversation->customer;
        $channel  = $conversation->channel;

        if (! $customer?->platform_id || ! $channel) {
            return 'Müşteri WhatsApp numarası bulunamadı';
        }

        // Telefon numarası ya da BSUID ("TR.1108540038525267" — telefonunu gizleyen
        // müşteri; recipient alanıyla gider) dışındaki adrese denemeden hata ver.
        if (! \App\Services\WhatsAppService::isSendableAddress($customer->platform_id)) {
            return 'Bu müşterinin geçerli bir WhatsApp adresi yok — panelden cevap iletilemez.';
        }

        $credentials = $channel->credentials ?? [];
        $settings    = $channel->settings ?? [];
        $accessToken   = $this->whatsAppService->getAccessToken($credentials);
        $phoneNumberId = $this->whatsAppService->getPhoneNumberId($settings);

        if (! $accessToken || ! $phoneNumberId) {
            Log::warning('MessageController: No WhatsApp credentials for channel', [
                'channel_id' => $channel->id,
            ]);
            return 'WhatsApp kanal kimlik bilgileri eksik';
        }

        if ($media) {
            // "[Fotoğraf]" gibi yer tutucular caption olarak gönderilmez
            $isPlaceholder = (bool) preg_match('/^\[(Fotoğraf|Video|Dosya[^\]]*)\]$/u', $content);
            $result = $this->whatsAppService->sendMedia(
                $customer->platform_id,
                $media['media_type'],
                $media['media_id'],
                $isPlaceholder ? null : $content,
                $media['filename'] ?? null,
                $phoneNumberId,
                $accessToken
            );
        } else {
            $result = $this->whatsAppService->sendMessage(
                $customer->platform_id,
                $content,
                $phoneNumberId,
                $accessToken
            );
        }

        if ($result['success'] && isset($result['message_id'])) {
            $message->update(['platform_message_id' => $result['message_id']]);
            return null;
        }

        return $result['error']['error']['message'] ?? 'WhatsApp gönderimi başarısız';
    }

    /**
     * Panelden eklenen dosyayı WhatsApp Cloud API'ye yükler (media_id döner) ve
     * panel önizlemesi için wa-media cache'ine kopyalar (media() endpoint'i önce
     * cache'e bakar — Meta'daki media_id 30 günde düşse de görsel panelde kalır).
     * Başarıda medya bilgisi dizisi, hatada 422 JsonResponse döner.
     */
    protected function uploadWhatsAppMedia(Request $request, Conversation $conversation): array|JsonResponse
    {
        $file = $request->file('attachment');
        $mime = strtolower($file->getMimeType() ?: $file->getClientMimeType());

        $mediaType = match (true) {
            in_array($mime, ['image/jpeg', 'image/png']) => 'image',
            $mime === 'video/mp4'                        => 'video',
            default                                      => 'document',
        };

        // Cloud API sınırları: image 5MB, video 16MB (document 100MB — upload validasyonu 16MB'a sabitli)
        $limitMb = $mediaType === 'image' ? 5 : 16;
        if ($file->getSize() > $limitMb * 1024 * 1024) {
            return response()->json(['message' => "Dosya çok büyük — WhatsApp sınırı bu tür için {$limitMb} MB."], 422);
        }

        $channel       = $conversation->channel;
        $accessToken   = $this->whatsAppService->getAccessToken($channel->credentials ?? []);
        $phoneNumberId = $this->whatsAppService->getPhoneNumberId($channel->settings ?? []);
        if (! $accessToken || ! $phoneNumberId) {
            return response()->json(['message' => 'WhatsApp kanal kimlik bilgileri eksik.'], 422);
        }

        $fileName = $file->getClientOriginalName() ?: ('dosya.' . ($file->guessExtension() ?: 'bin'));

        $result = $this->whatsAppService->uploadMedia(
            $file->getRealPath(),
            $mime,
            $fileName,
            $phoneNumberId,
            $accessToken
        );

        if (! $result['success']) {
            $err = $result['error']['error']['message'] ?? 'Medya yüklenemedi';
            return response()->json(['message' => 'WhatsApp medya yükleme hatası: ' . $err], 422);
        }

        $ext = match ($mime) {
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'video/mp4'       => 'mp4',
            'application/pdf' => 'pdf',
            default           => 'bin',
        };
        $dir = storage_path('app/wa-media');
        if (! is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        @copy($file->getRealPath(), "{$dir}/{$result['media_id']}.{$ext}");

        return [
            'media_type' => $mediaType,
            'media_id'   => $result['media_id'],
            'mime_type'  => $mime,
            'filename'   => $fileName,
        ];
    }

    /**
     * WhatsApp 24s penceresi kapalıyken sohbeti onaylı şablonla başlatır.
     * Şablonlar WhatsAppTemplateRegistry'den gelir (ortak şablonlar +
     * tenant'ın onaylı hazır cevap şablonları)
     * (hepsi UTILITY; {{1}} = firma, {{2}} = sipariş no / ürün adı). Müşteri
     * cevap verince pencere açılır ve normal serbest mesajlaşma devam eder.
     */
    public function sendWhatsAppTemplate(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'template'       => 'required|string|max:190',
            'template_param' => 'nullable|string|max:128',
        ]);

        $customer = $conversation->customer;
        $channel  = $conversation->channel;

        if (($channel->type ?? null) !== 'whatsapp' || ! $customer?->platform_id) {
            return response()->json(['message' => 'Bu konuşma için şablon gönderilemez.'], 422);
        }

        if (! \App\Services\WhatsAppService::isSendableAddress($customer->platform_id)) {
            return response()->json([
                'message' => 'Bu müşterinin geçerli bir WhatsApp adresi yok — şablon iletilemez.',
            ], 422);
        }

        $credentials   = $channel->credentials ?? [];
        $settings      = $channel->settings ?? [];
        $accessToken   = $this->whatsAppService->getAccessToken($credentials);
        $phoneNumberId = $this->whatsAppService->getPhoneNumberId($settings);

        if (! $accessToken || ! $phoneNumberId) {
            return response()->json(['message' => 'WhatsApp kanal kimlik bilgileri eksik.'], 422);
        }

        // Şablon listesi tenant'a göre değişir (hazır cevap şablonları WABA'ya bağlı).
        $def = \App\Services\WhatsAppTemplateRegistry::find((int) $channel->tenant_id, $validated['template']);
        if (! $def) {
            return response()->json(['message' => 'Şablon bulunamadı veya bu firma için onaylı değil.'], 422);
        }
        if (! empty($def['param_label']) && trim((string) ($validated['template_param'] ?? '')) === '') {
            return response()->json(['message' => $def['param_label'] . ' gerekli.'], 422);
        }

        // Meta'nın PAZARLAMA saydığı şablon, listeden çıkmış numaraya gitmez.
        $block = \App\Services\WhatsAppTemplatePolicy::blockReason(
            (int) $channel->tenant_id, $customer->platform_id, $validated['template'], $def['label']
        );
        if ($block) {
            return response()->json(['message' => $block], 422);
        }

        $param    = trim((string) ($validated['template_param'] ?? ''));
        $firmName = \App\Models\Tenant::find($channel->tenant_id)?->name ?? 'firmamız';

        $result = $this->whatsAppService->sendTemplate(
            $customer->platform_id,
            $validated['template'],
            'tr',
            \App\Services\WhatsAppTemplateRegistry::components($def, $firmName, $param),
            $phoneNumberId,
            $accessToken
        );

        if (! $result['success']) {
            $metaError = $result['error']['error']['message'] ?? 'Şablon gönderilemedi';
            // Şablon henüz Meta onayından geçmediyse anlaşılır mesaj ver
            if (str_contains($metaError, 'does not exist') || str_contains($metaError, 'Template')) {
                $metaError = 'Şablon henüz Meta tarafından onaylanmamış olabilir. (' . $metaError . ')';
            }
            return response()->json(['message' => $metaError], 422);
        }

        $message = $conversation->messages()->create([
            'sender_type'         => 'agent',
            'sender_id'           => $request->user()->id,
            'content'             => \App\Services\WhatsAppTemplateRegistry::render($def, $firmName, $param),
            'platform_message_id' => $result['message_id'] ?? null,
            'metadata'            => ['whatsapp_template' => $validated['template']],
        ]);

        $conversation->update(['last_message_at' => now()]);

        $message->load('sender:id,name,avatar');

        return response()->json($message, 201);
    }

    /**
     * SMS kanalı (Netgsm) — TEK YÖNLÜ: başlıkla gider, müşteri cevap yazamaz.
     * Panelden atılan her mesaj yeni bir SMS olarak gönderilir.
     */
    protected function sendToSms(Conversation $conversation, Message $message, string $content): ?string
    {
        $phone = $conversation->customer?->phone ?: $conversation->customer?->platform_id;
        if (! $phone) {
            return 'Müşteri telefon numarası bulunamadı';
        }

        $result = app(\App\Services\Marketing\Channels\SmsDriver::class)
            ->send($conversation->tenant_id, $phone, ['body' => $content]);

        if (! $result->success) {
            return $result->error ?? 'SMS gönderilemedi';
        }

        if ($result->providerMessageId) {
            $message->update(['platform_message_id' => $result->providerMessageId]);
        }

        // sms_to damgası: gelen cevabı bu konuşmaya bağlayan çapa (bkz sms:poll-inbox).
        $this->tagMessage($message, ['sms_sent' => true, 'sms_to' => $phone]);

        return null;
    }

    /** @return string|null gönderim hatası (varsa) — panelde rozet + hata bandı olur */
    protected function sendToTrendyol(Conversation $conversation, Message $message, string $content): ?string
    {
        $metadata = $conversation->metadata ?? [];
        $questionId = $metadata['trendyol_question_id'] ?? null;

        if (! $questionId) {
            Log::warning('MessageController: No trendyol_question_id in conversation metadata', [
                'conversation_id' => $conversation->id,
            ]);
            return 'Trendyol soru numarası bulunamadı';
        }

        $service = TrendyolService::fromChannel($conversation->channel);
        if (! $service->isAvailable()) {
            Log::error('MessageController: Trendyol credentials missing', ['channel_id' => $conversation->channel_id]);
            return 'Trendyol bağlantı bilgileri eksik';
        }

        $result = $service->answerQuestion((int) $questionId, $content);

        if (! $result['success']) {
            Log::error('MessageController: Trendyol answer failed', [
                'question_id' => $questionId,
                'error' => $result['error'] ?? null,
            ]);
            return $result['error'] ?? 'Trendyol cevabı kabul etmedi';
        }

        $message->update(['platform_message_id' => "ta_answered_{$questionId}"]);
        $conversation->update(['status' => 'resolved']);

        return null;
    }

    /** @return string|null gönderim hatası (varsa) — panelde rozet + hata bandı olur */
    protected function sendToHepsiburada(Conversation $conversation, Message $message, string $content): ?string
    {
        $metadata = $conversation->metadata ?? [];
        $issueNumber = $metadata['hepsiburada_question_id'] ?? null;

        if (! $issueNumber) {
            Log::warning('MessageController: No hepsiburada_question_id in conversation metadata', [
                'conversation_id' => $conversation->id,
            ]);
            return 'Hepsiburada soru numarası bulunamadı';
        }

        $service = HepsiburadaService::fromChannel($conversation->channel);
        if (! $service->isAvailable()) {
            Log::error('MessageController: Hepsiburada credentials missing', ['channel_id' => $conversation->channel_id]);
            return 'Hepsiburada bağlantı bilgileri eksik';
        }

        $result = $service->answerQuestion($issueNumber, $content);

        if (! $result['success']) {
            Log::error('MessageController: Hepsiburada answer failed', [
                'issue_number' => $issueNumber,
                'error' => $result['error'] ?? null,
            ]);
            return $result['error'] ?? 'Hepsiburada cevabı kabul etmedi';
        }

        $message->update(['platform_message_id' => "hba_{$issueNumber}"]);
        $conversation->update(['status' => 'resolved']);

        return null;
    }

    protected function sendToTicimax(Conversation $conversation, Message $message, string $content): ?string
    {
        $metadata = $conversation->metadata ?? [];
        $ticketId = $metadata['ticimax_ticket_id'] ?? null;

        if (! $ticketId) {
            Log::warning('MessageController: No ticimax_ticket_id in conversation metadata', [
                'conversation_id' => $conversation->id,
            ]);
            return 'Ticimax ticket ID bulunamadı';
        }

        $service = TicimaxService::fromChannel($conversation->channel);
        if (! $service->isAvailable()) {
            Log::error('MessageController: Ticimax credentials missing', ['channel_id' => $conversation->channel_id]);
            return 'Ticimax bağlantı bilgileri eksik';
        }

        // UyeId = 1 → admin/yönetici olarak cevapla (UyeId=0 Ticimax tarafından sessizce yok sayılır!)
        $result = $service->answerTicket((int) $ticketId, $content, 1);

        if (! $result['success']) {
            Log::error('MessageController: Ticimax answer API failed', [
                'ticket_id' => $ticketId,
                'error' => $result['error'] ?? null,
            ]);
            return 'Ticimax API hatası: ' . ($result['error'] ?? 'Bilinmeyen hata');
        }

        // Ticimax API her zaman success dönebilir — mesajın gerçekten kaydedildiğini doğrula
        usleep(500000); // 0.5s bekle
        $verifyResult = $service->getTicketMessages((int) $ticketId);

        if ($verifyResult['success']) {
            $msgs = $verifyResult['data']['GetSupportTicketMessagesResult']['DestekMesajlari']['ServisMusteriCevap'] ?? [];
            if (isset($msgs['ID'])) {
                $msgs = [$msgs];
            }

            // Son mesajın bizim gönderdiğimiz mesaj olup olmadığını kontrol et
            $lastMsg = end($msgs);
            $verified = false;
            if ($lastMsg) {
                $lastContent = $lastMsg['Cevap'] ?? '';
                $cevaplayanStr = $lastMsg['CevaplayanStr'] ?? '';
                if ($cevaplayanStr !== 'Müşteri' && mb_substr($lastContent, 0, 50) === mb_substr($content, 0, 50)) {
                    $verified = true;
                    $newMsgId = $lastMsg['ID'] ?? null;
                    if ($newMsgId) {
                        $message->update(['platform_message_id' => "tcm_m_{$newMsgId}"]);
                    }
                }
            }

            if (! $verified) {
                Log::error('MessageController: Ticimax answer not verified - message not found in thread', [
                    'ticket_id' => $ticketId,
                    'conversation_id' => $conversation->id,
                ]);
                return 'Mesaj Ticimax\'a iletilemedi — lütfen Ticimax panelinden kontrol edin';
            }
        }

        $conversation->update(['status' => 'resolved']);

        // Ticimax'ta talebi "Cevaplandı" durumuna güncelle (DurumID: 2)
        $statusResult = $service->updateTicketStatus((int) $ticketId, 2);
        if (! $statusResult['success']) {
            Log::warning('MessageController: Ticimax ticket status update failed', [
                'ticket_id' => $ticketId,
                'error' => $statusResult['error'] ?? null,
            ]);
        }

        return null;
    }

    protected function sendEmailReply(Conversation $conversation, Message $message, string $content): ?string
    {
        $channel = $conversation->channel;
        if (! $channel) {
            return 'Kanal bulunamadı';
        }

        $service = new EmailService($channel);

        // Find last incoming email for this conversation
        $lastEmail = \App\Models\EmailMessage::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'incoming')
            ->orderByDesc('created_at')
            ->first();

        if (! $lastEmail) {
            return 'Yanıtlanacak e-posta bulunamadı';
        }

        $result = $service->sendReply(
            $lastEmail->from_email,
            $lastEmail->subject ?? '(Konu yok)',
            $content,
            $lastEmail->message_id,
            $lastEmail->references
        );

        if (! $result['success']) {
            return $result['error'] ?? 'E-posta gönderilemedi';
        }

        $sentMessageId = $result['message_id'] ?? ('panel_reply_' . $lastEmail->id . '_' . time());
        $message->update(['platform_message_id' => $sentMessageId]);

        // Firma SMTP'si kullanılamayıp yerel sendmail'e düşüldüyse teslim ŞÜPHELİDİR
        // (hub IP'si firmanın SPF'inde yok + DKIM imzası atılamıyor → Gmail/Outlook
        // reddedebilir). Sessizce "gönderildi" deme: mesajı işaretle ve konuşmayı
        // açık/okunmamış bırak ki personel görsün. Bkz EmailService::sendReply.
        if (! empty($result['fallback'])) {
            $meta = $message->metadata ?? [];
            $meta['delivery_warning'] = 'Firmanın SMTP sunucusuna bağlanılamadı; e-posta hub sunucusundan gönderildi — alıcıya ulaşmamış olabilir.';
            $meta['delivery_warning_at'] = now()->toISOString();
            $meta['delivery_warning_reason'] = $result['fallback_reason'] ?? null;
            $message->update(['metadata' => $meta]);

            $conversation->update(['status' => 'open', 'is_read' => false]);
        }

        // Save outgoing EmailMessage for dedup with Sent folder sync
        try {
            $emailAddress = $channel->credentials['email_address'] ?? '';
            \App\Models\EmailMessage::create([
                'tenant_id' => $channel->tenant_id,
                'channel_id' => $channel->id,
                'message_id' => $sentMessageId,
                'in_reply_to' => $lastEmail->message_id,
                'references' => trim(($lastEmail->references ?? '') . ' <' . $lastEmail->message_id . '>'),
                'from_email' => strtolower(trim($emailAddress)),
                'from_name' => null,
                'to_email' => $lastEmail->from_email,
                'subject' => 'Re: ' . ($lastEmail->subject ?? '(Konu yok)'),
                'body_text' => $content,
                'body_html' => null,
                'ai_category' => 'outgoing_reply',
                'direction' => 'outgoing',
                'conversation_id' => $conversation->id,
                'processed_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('MessageController: Failed to save outgoing EmailMessage', [
                'error' => $e->getMessage(),
                'conversation_id' => $conversation->id,
            ]);
        }

        return null;
    }

    /**
     * WhatsApp medyasını (fotoğraf/video/ses/belge) panele servis eder.
     * Webhook yalnız media_id kaydeder; içerik ilk istekte Meta Graph'tan indirilip
     * diske cache'lenir (WA medya URL'leri ~5 dk, media_id ~30 gün geçerli —
     * cache sayesinde süresi dolsa da panelde görünmeye devam eder).
     */
    public function media(Message $message)
    {
        $meta = $message->metadata ?? [];
        $mediaId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($meta['media_id'] ?? ''));
        abort_if($mediaId === '', 404, 'Medya yok');

        $dir = storage_path('app/wa-media');
        if (! is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        foreach (glob("{$dir}/{$mediaId}.*") ?: [] as $cached) {
            return response()->file($cached);
        }

        $channel = $message->conversation?->channel;
        // Medya indirmede USER token şart: lookaside.fbsbx.com page token'ı reddediyor
        // (500). user_access_token OAuth'ta kaydedilir; yoksa page token yine de denenir.
        $creds = $channel->credentials ?? [];
        $token = $creds['user_access_token']
            ?? ($channel ? $this->whatsAppService->getAccessToken($creds) : null);
        abort_unless($token, 404, 'Kanal erişimi yok');

        $info = Http::withToken($token)->get(config('meta.graph_api_url') . '/' . $mediaId);
        abort_if($info->failed() || ! $info->json('url'), 404, 'Medya süresi dolmuş');

        $bin = Http::withToken($token)->timeout(30)->get($info->json('url'));
        abort_if($bin->failed(), 404, 'Medya indirilemedi');

        $mime = $info->json('mime_type') ?? ($meta['mime_type'] ?? 'application/octet-stream');
        $ext = match (true) {
            str_contains($mime, 'jpeg') => 'jpg',
            str_contains($mime, 'png') => 'png',
            str_contains($mime, 'webp') => 'webp',
            str_contains($mime, 'gif') => 'gif',
            str_contains($mime, 'mp4') => 'mp4',
            str_contains($mime, 'ogg'), str_contains($mime, 'opus') => 'ogg',
            str_contains($mime, 'mpeg') => 'mp3',
            str_contains($mime, 'pdf') => 'pdf',
            default => 'bin',
        };
        $path = "{$dir}/{$mediaId}.{$ext}";
        file_put_contents($path, $bin->body());

        return response()->file($path, ['Content-Type' => $mime]);
    }

    public function togglePenalty(Request $request, int $messageId): JsonResponse
    {
        $user = $request->user();

        // Only admins and super admins can penalize
        if (! $user->isSuperAdmin() && ! $user->isAdmin()) {
            return response()->json(['message' => 'Yetkiniz yok.'], 403);
        }

        // Tenant kapsamı KORUNMALI: withoutGlobalScopes() cross-tenant IDOR açıyordu
        // (bir tenant admini başka tenant'ın mesajına ceza yazabiliyordu). Trait sayesinde
        // süper-admin (tenant_id=null) yine tüm kayıtları görür; normal admin kendi tenant'ıyla sınırlanır.
        $message = Message::findOrFail($messageId);

        // Only agent messages can be penalized
        if ($message->sender_type !== 'agent') {
            return response()->json(['message' => 'Sadece temsilci mesajları işaretlenebilir.'], 422);
        }

        $validated = $request->validate([
            'points' => 'required|integer|in:0,1',
        ]);

        $points = $validated['points'];

        $message->update([
            'penalty_points' => $points,
            'penalized_by' => $points > 0 ? $user->id : null,
        ]);

        $message->load('sender:id,name,avatar', 'penalizedByUser:id,name');

        return response()->json($message);
    }

    /**
     * Update the most recent AiResponseLog for this conversation to reflect approval/edit status.
     */
    protected function updateAiResponseLog(Conversation $conversation, array $validated): void
    {
        try {
            $log = AiResponseLog::where('conversation_id', $conversation->id)
                ->orderByDesc('created_at')
                ->first();

            if (! $log) {
                return;
            }

            $wasEdited = ! empty($validated['ai_original_content'])
                && $validated['ai_original_content'] !== $validated['content'];

            $log->update([
                'was_approved' => true,
                'was_edited' => $wasEdited,
                'final_response' => $validated['content'],
            ]);
        } catch (\Exception $e) {
            Log::warning('MessageController::updateAiResponseLog failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Auto-learn: create training data from agent's response paired with customer's last message.
     * If AI response was edited, the edited version becomes the training answer (higher quality).
     */
    protected function autoLearn(Conversation $conversation, array $validated): void
    {
        try {
            // Get last customer message as the "question"
            $lastCustomerMessage = $conversation->messages()
                ->where('sender_type', 'customer')
                ->orderByDesc('created_at')
                ->first();

            if (! $lastCustomerMessage || empty($lastCustomerMessage->content)) {
                return;
            }

            $question = $lastCustomerMessage->content;
            $answer = $validated['content'];

            // Skip very short messages (greetings etc. are already in training data)
            if (mb_strlen($question) < 5 || mb_strlen($answer) < 10) {
                return;
            }

            // Determine quality: unedited AI = best (AI nailed it), edited AI = good, manual = baseline
            $isAiGenerated = $validated['is_ai_generated'] ?? false;
            $aiOriginal = $validated['ai_original_content'] ?? null;
            $wasEdited = $isAiGenerated && $aiOriginal && $aiOriginal !== $answer;

            // Quality score: unedited AI = 0.95, edited AI = 0.85, manual = 0.80
            $qualityScore = $isAiGenerated ? ($wasEdited ? 0.85 : 0.95) : 0.80;

            $channelType = $conversation->channel->type ?? null;

            AiTrainingData::create([
                'tenant_id' => $conversation->tenant_id,
                'source_type' => 'conversation',
                'source_id' => $conversation->id,
                'channel_type' => $channelType,
                'question' => $question,
                'answer' => $answer,
                'context' => [
                    'conversation_id' => $conversation->id,
                    'was_ai_edited' => $wasEdited,
                    'is_ai_generated' => $isAiGenerated,
                ],
                'quality_score' => $qualityScore,
                'is_active' => true,
                'used_count' => 0,
            ]);
        } catch (\Exception $e) {
            Log::warning('MessageController::autoLearn failed', ['error' => $e->getMessage()]);
        }
    }
}
