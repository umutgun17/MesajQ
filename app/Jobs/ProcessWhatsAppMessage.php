<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public array $data,
    ) {}

    public function handle(WhatsAppService $whatsAppService): void
    {
        $phoneNumberId = $this->data['phone_number_id'];
        $waId          = $this->data['wa_id'];
        $messageId     = $this->data['message_id'];

        if (! $phoneNumberId || ! $waId || ! $messageId) {
            Log::warning('ProcessWhatsAppMessage: Missing required fields', $this->data);
            return;
        }

        // Find channel by phone_number_id in settings (bypass tenant scope).
        // orderBy(id) determinizm için; çok-tenant çakışması hata loglanır.
        $matches = Channel::withoutGlobalScopes()
            ->where('type', 'whatsapp')
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(function ($ch) use ($phoneNumberId) {
                $settings = $ch->settings ?? [];
                return ($settings['phone_number_id'] ?? null) === $phoneNumberId;
            });

        if ($matches->count() > 1 && $matches->pluck('tenant_id')->unique()->count() > 1) {
            Log::error('ProcessWhatsAppMessage: phone_number_id birden fazla tenant kanalıyla eşleşti — çakışma!', [
                'phone_number_id' => $phoneNumberId,
                'channels' => $matches->map(fn ($c) => ['id' => $c->id, 'tenant_id' => $c->tenant_id])->values()->all(),
            ]);
        }

        $channel = $matches->first();

        if (! $channel) {
            Log::warning('ProcessWhatsAppMessage: No channel found', [
                'phone_number_id' => $phoneNumberId,
            ]);
            return;
        }

        $tenantId    = $channel->tenant_id;
        $credentials = $channel->credentials ?? [];
        $settings    = $channel->settings ?? [];
        $accessToken = $whatsAppService->getAccessToken($credentials);

        // Find or create customer (platform_id = wa_id = phone number)
        $customer = Customer::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id'     => $tenantId,
                'platform_id'   => $waId,
                'platform_type' => 'whatsapp',
            ],
            [
                'name' => $this->data['contact_name'] ?? 'WhatsApp User',
            ]
        );

        // Update name if we have a better one from contact profile
        if ($this->data['contact_name'] && $customer->name === 'WhatsApp User') {
            $customer->update(['name' => $this->data['contact_name']]);
        }

        // Kullanıcı adı akışı (telefon gizli): @kullanıcı adını müşteri kartına işle —
        // personel kimin yazdığını görsün, numara sipariş kaydından eşleştirilebilsin.
        $username = $this->data['username'] ?? null;
        if ($username && (($customer->metadata['wa_username'] ?? null) !== $username)) {
            $customer->update(['metadata' => array_merge($customer->metadata ?? [], ['wa_username' => $username])]);
        }

        // Find or create conversation (platform_conversation_id = waId_phoneNumberId)
        $conversationId = $waId . '_' . $phoneNumberId;
        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id'                => $tenantId,
                'channel_id'               => $channel->id,
                'customer_id'              => $customer->id,
                'platform_conversation_id' => $conversationId,
            ],
            [
                'type'   => 'message',
                'status' => 'open',
            ]
        );

        // Check for duplicate message
        $existingMessage = Message::where('platform_message_id', $messageId)
            ->where('conversation_id', $conversation->id)
            ->exists();

        if ($existingMessage) {
            return;
        }

        // Build message metadata
        $metadata = $this->data['metadata'];

        // Create message
        Message::create([
            'tenant_id'           => $tenantId,
            'conversation_id'     => $conversation->id,
            'sender_type'         => 'customer',
            'sender_id'           => null,
            'content'             => $this->data['text'],
            'platform_message_id' => $messageId,
            'metadata'            => $metadata,
        ]);

        // Update conversation
        $conversation->update([
            'last_message_at' => now(),
            'is_read'         => false,
            'unread_count'    => $conversation->unread_count + 1,
            'status'          => 'open',
        ]);

        Log::info('ProcessWhatsAppMessage: Message saved', [
            'conversation_id' => $conversation->id,
            'customer_id'     => $customer->id,
            'message_id'      => $messageId,
            'wa_id'           => $waId,
        ]);

        // "Listeden Çık" hızlı-yanıt butonu → numara WhatsApp karalistesine girer
        // (UnifiedSender üzerinden giden HER WA gönderimi — kampanya, sepet
        // hatırlatma, sipariş bildirimi — bastırılır) + müşteriye onay mesajı.
        $isOptOut = in_array($this->data['type'] ?? '', ['button', 'interactive'], true)
            && mb_strtolower(trim($this->data['text'] ?? '')) === 'listeden çık';
        if ($isOptOut) {
            \App\Models\MarketingSuppression::add(
                $tenantId, 'whatsapp', $waId,
                '"Listeden Çık" butonuna bastı (WhatsApp)', 'wa_optout_button'
            );
            $confirm = 'Talebiniz alındı, numaranız kampanya listemizden çıkarıldı. İyi günler dileriz. 🙏';
            $sent = $whatsAppService->sendMessage($waId, $confirm, $phoneNumberId, $accessToken);
            if (! empty($sent['success'])) {
                Message::create([
                    'tenant_id'           => $tenantId,
                    'conversation_id'     => $conversation->id,
                    'sender_type'         => 'agent',
                    'sender_id'           => null,
                    'content'             => $confirm,
                    'platform_message_id' => $sent['message_id'] ?? null,
                    'metadata'            => ['auto_optout_confirm' => true],
                ]);
                // Onay gittiyse konuşma otomatik ÇÖZÜLDÜ — temsilcinin yapacağı iş kalmadı
                $conversation->update([
                    'last_message_at' => now(),
                    'status'          => 'resolved',
                    'is_read'         => true,
                    'unread_count'    => 0,
                ]);
            }
            Log::info('ProcessWhatsAppMessage: opt-out — WhatsApp karalistesine eklendi', [
                'tenant_id' => $tenantId,
                'wa_id'     => $waId,
            ]);
        }

        // Check tenant auto-reply settings
        $tenant = Tenant::find($tenantId);
        $tenantSettings = $tenant?->settings ?? [];

        if (($tenantSettings['auto_reply'] ?? 'disabled') === 'auto') {
            $delayMin = max(1, (int) ($tenantSettings['auto_reply_delay_min'] ?? 3));
            $delayMax = max($delayMin, (int) ($tenantSettings['auto_reply_delay_max'] ?? 10));
            $delayMinutes = rand($delayMin, $delayMax);

            SendAutoReply::dispatch($conversation->id, $tenantId)
                ->delay(now()->addMinutes($delayMinutes));

            Log::info('ProcessWhatsAppMessage: Auto-reply scheduled', [
                'conversation_id' => $conversation->id,
                'delay_minutes'   => $delayMinutes,
                'delay_range'     => "{$delayMin}-{$delayMax}",
            ]);
        }
    }
}
