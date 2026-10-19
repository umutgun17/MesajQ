<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailMessage;
use App\Models\Tenant;
use App\Services\EmailService;
use App\Services\Marketing\Channels\SmsDriver;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mesajlar sayfası "+ Yeni konuşma": daha önce konuşması olmayan bir kişiye
 * panelden ilk mesajı göndermek. Yalnız BAŞLATILABİLİR kanallar sunulur:
 * WhatsApp (onaylı şablonla), e-posta (serbest) ve SMS (Netgsm, tek yönlü —
 * müşteri başlığa cevap yazamaz). IG/FB DM'de platform işletmenin önce
 * yazmasına izin vermez, o yüzden listede yoktur. Kanal listesi tenant'ın
 * AKTİF kanallarından türetilir (ör. Netgsm kanalı olmayan tenant SMS görmez).
 */
class NewConversationController extends Controller
{
    /**
     * WhatsApp açılış şablonları — her iki WABA'da Meta onaylı, hepsi UTILITY
     * (pazarlama tarifesinden kaçınmak için genel/pazarlama şablonu bilinçli YOK).
     * {param} = şablonun 2. değişkeni (sipariş no / ürün adı); altlarında
     * "Evet, müsaitim" / "Hayır, daha sonra" hızlı yanıt butonları vardır.
     */
    /**
     * Panelden gönderilebilen WhatsApp şablonları.
     *
     * Gövdedeki {firma} DAİMA {{1}}, {param} varsa {{2}}'dir — Meta'daki
     * şablon metniyle birebir aynı sırada olmalı. param_label null ise
     * şablon parametresizdir (temsilci hiçbir şey yazmaz).
     * buttons: Meta'da tanımlı hızlı-yanıt butonları (yalnız önizleme için;
     * statik butonlar gönderimde component gerektirmez).
     */
    public const WA_TEMPLATES = [
        'siparis_bilgilendirme' => [
            'label'       => 'Sipariş bilgilendirme',
            'category'    => 'UTILITY',
            'param_label' => 'Sipariş numarası',
            'body'        => 'Merhaba, size {firma} müşteri hizmetlerinden ulaşıyorum. {param} numaralı siparişinizle ilgili sizi bilgilendirmek istiyoruz. Görüşmek için şimdi müsait misiniz?',
            'buttons'     => [],
        ],
        'urun_bilgilendirme' => [
            'label'       => 'Ürün bilgilendirme',
            'category'    => 'UTILITY',
            'param_label' => 'Ürün adı',
            'body'        => 'Merhaba, size {firma} müşteri hizmetlerinden ulaşıyorum. {param} ürünüyle ilgili işleminiz hakkında sizi bilgilendirmek istiyoruz. Görüşmek için şimdi müsait misiniz?',
            'buttons'     => [],
        ],
        'nasil_yardimci_olabiliriz' => [
            'label'       => 'Genel karşılama (butonlu)',
            'category'    => 'MARKETING',
            'param_label' => null,
            'body'        => 'Merhaba, size {firma} müşteri hizmetlerinden ulaşıyorum. Size nasıl yardımcı olabiliriz? Detaylı görüşme sağlamak için şu anda müsait misiniz?',
            'buttons'     => ['Evet, müsaitim', 'Hayır, daha sonra'],
        ],
        'musait_misiniz' => [
            'label'       => 'Müsaitlik sorusu (butonlu)',
            'category'    => 'UTILITY',
            'param_label' => 'Konu (ör. siparişiniz, ürün değişimi)',
            'body'        => 'Merhaba, size {firma} müşteri hizmetlerinden ulaşıyorum. {param} ile ilgili detaylı görüşme sağlamak istiyoruz. Şu anda müsait misiniz?',
            'buttons'     => ['Evet, müsaitim', 'Hayır, daha sonra'],
        ],
        'gorusme_tesekkur' => [
            'label'       => 'Görüşme sonrası teşekkür',
            'category'    => 'UTILITY',
            'param_label' => null,
            'body'        => 'Görüşmemiz için çok teşekkür ederiz. {firma} olarak size yardımcı olabildiysek ne mutlu. Aklınıza takılan her konuda bu numaradan bize yazabilirsiniz. İyi günler dileriz.',
            'buttons'     => [],
        ],
    ];

    public function __construct(protected WhatsAppService $whatsAppService) {}

    protected function tenantId(Request $request): int
    {
        return (int) ($request->user()->tenant_id ?: $request->header('X-Tenant-Id', 0));
    }

    /**
     * Telefonu WhatsApp wa_id biçimine getirir (ülke kodlu, +'sız: 905XXXXXXXXX).
     * Webhook müşteri eşleşmesi bu biçime birebir bağlı — farklı yazılırsa
     * müşterinin cevabı ayrı konuşma açar.
     */
    protected function normalizePhone(?string $raw): ?string
    {
        $p = preg_replace('/\D+/', '', (string) $raw);
        if (str_starts_with($p, '00')) {
            $p = substr($p, 2);
        }
        if (strlen($p) === 11 && str_starts_with($p, '0')) {
            $p = '9' . $p; // 05XX... → 905XX...
        } elseif (strlen($p) === 10 && str_starts_with($p, '5')) {
            $p = '90' . $p; // 5XX... → 905XX...
        }

        // "0" ile başlayıp 11 haneden kısa kalan giriş = hane eksik yazılmış
        // TR numarası (ör. 0542793097). Meta'ya gönderilse 400 döner —
        // baştan reddet ki temsilci Türkçe hata görsün.
        if (str_starts_with($p, '0')) {
            return null;
        }

        return strlen($p) >= 11 ? $p : null;
    }

    /** Tenant'ın konuşma başlatabileceği aktif kanallar + WhatsApp şablonları. */
    public function options(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        if (! $tenantId) {
            return response()->json(['channels' => [], 'wa_templates' => []]);
        }

        $firmName = Tenant::withoutGlobalScopes()->find($tenantId)?->name ?? 'firmamız';
        $channels = [];

        foreach (Channel::where('is_active', true)->whereIn('type', ['whatsapp', 'email'])->get() as $ch) {
            $credentials = $ch->credentials ?? [];
            $settings    = $ch->settings ?? [];
            if ($ch->type === 'whatsapp') {
                $ready  = $this->whatsAppService->getAccessToken($credentials) && $this->whatsAppService->getPhoneNumberId($settings);
                $detail = $settings['display_number'] ?? null;
            } else {
                $ready  = ! empty($credentials['email_address']);
                $detail = $credentials['email_address'] ?? null;
            }
            if (! $ready) {
                continue;
            }
            $channels[] = ['channel_id' => $ch->id, 'type' => $ch->type, 'name' => $ch->name, 'detail' => $detail];
        }

        $smsDriver = app(SmsDriver::class);
        if ($smsDriver->isAvailable($tenantId)) {
            $header = Channel::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('type', 'like', 'netgsm%')->where('is_active', true)
                ->first()?->settings['sms_header']
                ?? (Tenant::withoutGlobalScopes()->find($tenantId)?->settings['sms_header'] ?? null);
            $channels[] = ['channel_id' => null, 'type' => 'sms', 'name' => 'SMS', 'detail' => $header];
        }

        $templates = [];
        // Ortak şablonlar + tenant'ın ONAYLI hazır cevap şablonları
        foreach (\App\Services\WhatsAppTemplateRegistry::forTenant($tenantId) as $key => $def) {
            $templates[] = [
                'key'         => $key,
                'label'       => $def['label'],
                'group'       => $def['group'],
                'param_label' => $def['param_label'],
                'buttons'     => $def['buttons'],
                'category'    => $def['category'],
                'preview'     => \App\Services\WhatsAppTemplateRegistry::render($def, $firmName, '______'),
            ];
        }

        return response()->json([
            'channels'        => $channels,
            'wa_templates'    => $templates,
            'quality_warning' => \App\Services\WhatsAppTemplatePolicy::qualityWarning($tenantId),
        ]);
    }

    /** Müşteri arama (isim/soyisim/e-posta/telefon) — modal içindeki kişi seçici. */
    public function searchCustomers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['customers' => []]);
        }
        $like = '%' . $q . '%';

        $customers = Customer::query()
            ->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('surname', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(name,''),' ',COALESCE(surname,'')) LIKE ?", [$like])
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            })
            ->orderByDesc('total_orders')
            ->limit(15)
            ->get(['id', 'name', 'surname', 'email', 'phone', 'platform_type', 'total_orders']);

        return response()->json(['customers' => $customers]);
    }

    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel_type' => 'required|in:whatsapp,email,sms',
            'channel_id'   => 'nullable|integer',
            'customer_id'  => 'nullable|integer',
            'name'         => 'nullable|string|max:120',
            'phone'        => 'nullable|string|max:32',
            'email'        => 'nullable|email|max:190',
            'template'     => 'nullable|string|max:190',
            'template_param' => 'nullable|string|max:128',
            'subject'      => 'nullable|string|max:255',
            'content'      => 'nullable|string|max:5000',
        ]);

        $tenantId = $this->tenantId($request);
        if (! $tenantId) {
            return response()->json(['message' => 'Tenant belirlenemedi.'], 422);
        }

        $picked = ! empty($validated['customer_id']) ? Customer::find($validated['customer_id']) : null;

        return match ($validated['channel_type']) {
            'whatsapp' => $this->startWhatsApp($request, $tenantId, $validated, $picked),
            'email'    => $this->startEmail($request, $tenantId, $validated, $picked),
            'sms'      => $this->startSms($request, $tenantId, $validated, $picked),
        };
    }

    protected function startWhatsApp(Request $request, int $tenantId, array $validated, ?Customer $picked): JsonResponse
    {
        $channel = Channel::where('type', 'whatsapp')->where('is_active', true)
            ->when(! empty($validated['channel_id']), fn ($q) => $q->where('id', $validated['channel_id']))
            ->first();
        if (! $channel) {
            return response()->json(['message' => 'Aktif WhatsApp kanalı bulunamadı.'], 422);
        }

        $accessToken   = $this->whatsAppService->getAccessToken($channel->credentials ?? []);
        $phoneNumberId = $this->whatsAppService->getPhoneNumberId($channel->settings ?? []);
        if (! $accessToken || ! $phoneNumberId) {
            return response()->json(['message' => 'WhatsApp kanal kimlik bilgileri eksik.'], 422);
        }

        $waId = $this->normalizePhone($validated['phone'] ?? $picked?->phone);
        if (! $waId) {
            return response()->json(['message' => 'Telefon numarası eksik veya hatalı görünüyor. 05XX XXX XX XX (11 hane) formatında girin.'], 422);
        }

        $tplKey = $validated['template'] ?? 'siparis_bilgilendirme';
        $def    = \App\Services\WhatsAppTemplateRegistry::find($tenantId, $tplKey);
        if (! $def) {
            return response()->json(['message' => 'Şablon bulunamadı veya bu firma için onaylı değil.'], 422);
        }
        $param = trim((string) ($validated['template_param'] ?? ''));
        if (! empty($def['param_label']) && $param === '') {
            return response()->json(['message' => $def['param_label'] . ' gerekli.'], 422);
        }

        // Meta'nın PAZARLAMA saydığı şablon, listeden çıkmış numaraya gitmez.
        if ($block = \App\Services\WhatsAppTemplatePolicy::blockReason($tenantId, $waId, $tplKey, $def['label'])) {
            return response()->json(['message' => $block], 422);
        }

        $firmName = Tenant::withoutGlobalScopes()->find($tenantId)?->name ?? 'firmamız';

        $result = $this->whatsAppService->sendTemplate(
            $waId,
            $tplKey,
            'tr',
            \App\Services\WhatsAppTemplateRegistry::components($def, $firmName, $param),
            $phoneNumberId,
            $accessToken
        );

        if (! $result['success']) {
            $metaError = $result['error']['error']['message'] ?? 'Şablon gönderilemedi';
            return response()->json(['message' => $metaError], 422);
        }

        // Müşteri + konuşma anahtarları webhook'takiyle (ProcessWhatsAppMessage)
        // birebir aynı — müşterinin cevabı böylece AYNI konuşmaya düşer.
        $customer = Customer::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'platform_id' => $waId, 'platform_type' => 'whatsapp'],
            [
                'channel_id' => $channel->id,
                'name'       => $validated['name'] ?? trim(($picked?->name ?? '') . ' ' . ($picked?->surname ?? '')) ?: 'WhatsApp User',
                'phone'      => $waId,
            ]
        );

        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id'                => $tenantId,
                'channel_id'               => $channel->id,
                'customer_id'              => $customer->id,
                'platform_conversation_id' => $waId . '_' . $phoneNumberId,
            ],
            ['type' => 'message', 'status' => 'resolved', 'is_read' => true]
        );

        $content = \App\Services\WhatsAppTemplateRegistry::render($def, $firmName, $param);
        $conversation->messages()->create([
            'sender_type'         => 'agent',
            'sender_id'           => $request->user()->id,
            'content'             => $content,
            'platform_message_id' => $result['message_id'] ?? null,
            'metadata'            => ['whatsapp_template' => $tplKey],
        ]);
        $conversation->update(['last_message_at' => now()]);

        return response()->json(['conversation_id' => $conversation->id], 201);
    }

    protected function startEmail(Request $request, int $tenantId, array $validated, ?Customer $picked): JsonResponse
    {
        $channel = Channel::where('type', 'email')->where('is_active', true)
            ->when(! empty($validated['channel_id']), fn ($q) => $q->where('id', $validated['channel_id']))
            ->first();
        if (! $channel) {
            return response()->json(['message' => 'Aktif e-posta kanalı bulunamadı.'], 422);
        }

        $to      = strtolower(trim((string) ($validated['email'] ?? $picked?->email)));
        $subject = trim((string) ($validated['subject'] ?? ''));
        $content = trim((string) ($validated['content'] ?? ''));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'Geçerli bir e-posta adresi girin.'], 422);
        }
        if ($subject === '' || $content === '') {
            return response()->json(['message' => 'Konu ve mesaj metni gerekli.'], 422);
        }

        $service = new EmailService($channel);
        $result  = $service->sendReply($to, $subject, $content, null, null);
        if (! $result['success']) {
            return response()->json(['message' => $result['error'] ?? 'E-posta gönderilemedi.'], 422);
        }

        $customer = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('email', $to)->first()
            ?? Customer::withoutGlobalScopes()->create([
                'tenant_id'     => $tenantId,
                'channel_id'    => $channel->id,
                'name'          => $validated['name'] ?? $to,
                'email'         => $to,
                'platform_id'   => $to,
                'platform_type' => 'email',
            ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'tenant_id'                => $tenantId,
            'channel_id'               => $channel->id,
            'customer_id'              => $customer->id,
            'type'                     => 'message',
            'status'                   => 'resolved',
            'is_read'                  => true,
            'last_message_at'          => now(),
            'platform_conversation_id' => 'email_' . Str::random(16),
            'metadata'                 => ['email_subject' => $subject, 'email_to' => $to],
        ]);

        $sentMessageId = $result['message_id'] ?? ('panel_new_' . $conversation->id . '_' . time());

        // Giden e-postayı kaydet: müşterinin cevabı In-Reply-To başlığıyla bu
        // message_id'ye eşlenir → cevap aynı konuşmaya düşer (EmailService kural 1).
        try {
            EmailMessage::create([
                'tenant_id'       => $tenantId,
                'channel_id'      => $channel->id,
                'message_id'      => $sentMessageId,
                'from_email'      => strtolower(trim($channel->credentials['email_address'] ?? '')),
                'to_email'        => $to,
                'subject'         => $subject,
                'body_text'       => $content,
                'ai_category'     => 'outgoing_reply',
                'direction'       => 'outgoing',
                'conversation_id' => $conversation->id,
                'processed_at'    => now(),
            ]);
        } catch (\Exception $e) {
            Log::warning('NewConversation: outgoing EmailMessage kaydedilemedi', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
        }

        $conversation->messages()->create([
            'sender_type'         => 'agent',
            'sender_id'           => $request->user()->id,
            'content'             => $content,
            'platform_message_id' => $sentMessageId,
            'metadata'            => ['email_subject' => $subject],
        ]);

        return response()->json(['conversation_id' => $conversation->id], 201);
    }

    protected function startSms(Request $request, int $tenantId, array $validated, ?Customer $picked): JsonResponse
    {
        $smsDriver = app(SmsDriver::class);
        if (! $smsDriver->isAvailable($tenantId)) {
            return response()->json(['message' => 'Bu firmada SMS (Netgsm) kanalı tanımlı değil.'], 422);
        }

        $phone   = $this->normalizePhone($validated['phone'] ?? $picked?->phone);
        $content = trim((string) ($validated['content'] ?? ''));
        if (! $phone) {
            return response()->json(['message' => 'Geçerli bir telefon numarası girin.'], 422);
        }
        if ($content === '') {
            return response()->json(['message' => 'Mesaj metni gerekli.'], 422);
        }

        $result = $smsDriver->send($tenantId, $phone, ['body' => $content]);
        if (! $result->success) {
            return response()->json(['message' => $result->error ?? 'SMS gönderilemedi.'], 422);
        }

        // SMS için sanal kanal kaydı (tenant başına bir kez oluşur)
        $channel = Channel::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'type' => 'sms'],
            ['name' => 'SMS', 'category' => 'Gelen Mesaj', 'is_active' => true, 'credentials' => [], 'settings' => []]
        );

        $customer = $picked ?? Customer::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'platform_id' => $phone, 'platform_type' => 'sms'],
            ['channel_id' => $channel->id, 'name' => $validated['name'] ?? $phone, 'phone' => $phone]
        );

        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id'                => $tenantId,
                'channel_id'               => $channel->id,
                'customer_id'              => $customer->id,
                'platform_conversation_id' => 'sms_' . $phone,
            ],
            [
                'type'     => 'message',
                'status'   => 'resolved',
                'is_read'  => true,
                'metadata' => ['sms_one_way' => true],
            ]
        );

        $conversation->messages()->create([
            'sender_type'         => 'agent',
            'sender_id'           => $request->user()->id,
            'content'             => $content,
            'platform_message_id' => $result->providerMessageId,
            'metadata'            => ['sms_to' => $phone],
        ]);
        $conversation->update(['last_message_at' => now()]);

        return response()->json(['conversation_id' => $conversation->id], 201);
    }
}
