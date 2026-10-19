<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\NetgsmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NetGSM GELEN SMS → Mesajlar paneli.
 *
 * Neden: müşteriye SMS ile bilgilendirme/dönüş yapıyoruz ve müşteri buna SMS ile
 * cevap veriyor; bu cevaplar bugüne kadar NetGSM kutusunda ÖLÜ kalıyordu
 * (4 Eyl denetimi: 25 Ağustos'tan beri cevapsız "iadem hâlâ yapılmadı" mesajı).
 *
 * Akış: kutu taranır → NetGSM mesaj id'si ile tekilleştirilir (messages.platform_message_id
 * = "netgsm:<id>") → numara müşteriyle eşleşirse SON konuşmasına eklenir ve konuşma
 * yeniden AÇILIR; eşleşme yoksa SMS kanalında yeni konuşma açılır.
 *
 * Pencere: son işlenen zaman damgası tenant bazında cache'te tutulur; ilk çalışmada
 * (veya cache boşsa) --hours kadar geriye bakar. Tekilleştirme id ile olduğu için
 * pencerenin çakışması sorun değildir.
 */
class SmsPollInbox extends Command
{
    protected $signature = 'sms:poll-inbox
        {--tenant= : Yalnız bu tenant}
        {--hours=2 : Cache yoksa kaç saat geriye bakılsın}
        {--ignore-cursor : Son tarama imlecini yok say (geriye dönük toplama)}
        {--dry : Yazma yapma, ne olacağını göster}';

    protected $description = 'NetGSM gelen SMS kutusunu tarar ve mesajları Mesajlar paneline aktarır';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q) => $q->where('id', (int) $this->option('tenant')))
            ->get();

        $total = 0;

        foreach ($tenants as $tenant) {
            $netgsmChannel = Channel::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('type', 'like', 'netgsm%')
                ->where('is_active', true)
                ->first();

            if (! $netgsmChannel) {
                continue;
            }

            $svc = NetgsmService::fromChannel($netgsmChannel);
            if (! $svc->isAvailable()) {
                continue;
            }

            $cacheKey = "sms:inbox:cursor:{$tenant->id}";
            $since = (Cache::get($cacheKey) && ! $this->option('ignore-cursor'))
                ? now()->parse(Cache::get($cacheKey))->subMinutes(10) // çakışma payı
                : now()->subHours((int) $this->option('hours'));

            $messages = $svc->getInbox($since->format('dmYHi'), now()->format('dmYHi'));
            if ($messages === null) {
                $this->warn("t{$tenant->id}: NetGSM kutusu okunamadı");

                continue;
            }

            $imported = 0;
            foreach ($messages as $m) {
                if ($this->import($tenant, $m, (bool) $this->option('dry'))) {
                    $imported++;
                }
            }

            if (! $this->option('dry')) {
                Cache::put($cacheKey, now()->toIso8601String(), now()->addDays(7));
            }

            $total += $imported;
            $this->line("t{$tenant->id}: kutuda " . count($messages) . " mesaj, {$imported} yeni");
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $m */
    protected function import(Tenant $tenant, array $m, bool $dry): bool
    {
        $externalId = (string) ($m['id'] ?? $m['gorevId'] ?? '');
        $phoneRaw = (string) ($m['gonderen'] ?? '');
        $body = trim((string) ($m['mesaj'] ?? ''));
        if ($externalId === '' || $phoneRaw === '' || $body === '') {
            return false;
        }

        $platformId = 'netgsm:' . $externalId;
        if (Message::withoutGlobalScopes()->where('platform_message_id', $platformId)->exists()) {
            return false; // zaten alınmış
        }

        // kayittar epoch(ms) — SMS'in geliş anı; yoksa şimdi
        $ts = (int) ($m['kayittar'] ?? 0);
        $at = $ts > 0 ? now()->createFromTimestampMs($ts) : now();

        $phone = preg_replace('/\D+/', '', $phoneRaw);
        $tail = substr($phone, -10);

        if ($dry) {
            $this->line("  [dry] {$phone}: " . mb_substr($body, 0, 60));

            return true;
        }

        return DB::transaction(function () use ($tenant, $phone, $tail, $body, $platformId, $at) {
            $conversation = $this->resolveConversation($tenant, $phone, $tail);
            if (! $conversation) {
                return false;
            }

            Message::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'sender_type' => 'customer',
                'content' => $body,
                'platform_message_id' => $platformId,
                'metadata' => ['source' => 'netgsm_inbox', 'from' => $phone],
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            $conversation->forceFill([
                'status' => 'open',
                'is_read' => false,
                'unread_count' => (int) $conversation->unread_count + 1,
                'last_message_at' => $at,
            ])->save();

            Log::info('[SMS] gelen mesaj panele aktarıldı', [
                'tenant' => $tenant->id,
                'conversation' => $conversation->id,
            ]);

            return true;
        });
    }

    /**
     * Numaraya ait AÇIK/yakın tarihli konuşmayı bulur; yoksa SMS kanalında yeni açar.
     * Böylece chatbot devrine gelen cevap aynı konuşmanın altına düşer, personel
     * bağlamı kaybetmez.
     */
    protected function resolveConversation(Tenant $tenant, string $phone, string $tail): ?Conversation
    {
        // 1) EN GÜVENİLİR BAĞ: bu numaraya SMS'i hangi konuşmadan gönderdiysek
        // cevabı da oraya koy. Müşteri kaydından bağımsızdır — aynı telefon için
        // birden çok müşteri kaydı olabiliyor (biri phone, diğeri platform_id ile;
        // 4 Eyl'de giden SMS webchat conv'undan gitti, cevap AYRI bir sms
        // konuşması açtı ve personel bağlamı kaybetti).
        $repliedTo = Conversation::withoutGlobalScopes()
            ->where('conversations.tenant_id', $tenant->id)
            ->where('last_message_at', '>=', now()->subDays(30))
            ->whereHas('messages', fn ($q) => $q
                ->where('sender_type', '!=', 'customer')
                ->whereRaw("metadata->>'sms_to' LIKE ?", ['%' . $tail]))
            ->orderByDesc('last_message_at')
            ->first();

        if ($repliedTo) {
            $this->markForceSms($repliedTo);

            return $repliedTo;
        }

        // 2) Numaraya ait müşteri kayıtlarının HEPSİ taranır (tekini seçmek yanlış
        // olanı seçebiliyordu), en yeni sms/webchat konuşması kazanır.
        $customerIds = Customer::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($q) => $q->where('phone', 'LIKE', '%' . $tail)->orWhere('platform_id', 'LIKE', '%' . $tail))
            ->pluck('id');

        $customer = $customerIds->isNotEmpty()
            ? Customer::withoutGlobalScopes()->whereKey($customerIds)->orderByDesc('id')->first()
            : null;

        if ($customerIds->isNotEmpty()) {
            // SADECE SMS ile cevaplanabilen kanallara bağla. WhatsApp/Instagram
            // konuşmasına eklersek personelin cevabı oradan gitmeye çalışır
            // (WA 24 saat penceresi vb.) ve müşteriye SMS ulaşmaz.
            $existing = Conversation::withoutGlobalScopes()
                ->where('conversations.tenant_id', $tenant->id)
                ->whereIn('customer_id', $customerIds)
                ->where('last_message_at', '>=', now()->subDays(30))
                ->whereHas('channel', fn ($q) => $q->whereIn('type', ['sms', 'webchat']))
                ->orderByDesc('last_message_at')
                ->first();

            if ($existing) {
                $this->markForceSms($existing);

                return $existing;
            }
        }

        $channel = Channel::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'type' => 'sms'],
            ['name' => 'SMS', 'category' => 'message', 'is_active' => true]
        );

        $customer ??= Customer::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'name' => 'SMS ' . $phone,
            'phone' => $phone,
            'platform_id' => $phone,
            'platform_type' => 'sms',
        ]);

        return Conversation::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'customer_id' => $customer->id,
            'type' => 'message',
            'status' => 'open',
            'is_read' => false,
            'unread_count' => 0,
            'last_message_at' => now(),
            'platform_conversation_id' => 'sms-' . $phone,
            'metadata' => ['source' => 'netgsm_inbox'],
        ]);
    }

    /**
     * Müşteri SMS'e geçtiyse bundan sonraki personel cevapları da SMS'ten gitsin
     * (mesai içi olsa bile — kişi sohbet sayfasında değil, telefonunda).
     */
    protected function markForceSms(Conversation $conversation): void
    {
        if (($conversation->channel->type ?? '') !== 'webchat') {
            return;
        }

        $meta = $conversation->metadata ?? [];
        if (empty($meta['force_sms'])) {
            $meta['force_sms'] = true;
            $conversation->forceFill(['metadata' => $meta])->save();
        }
    }
}
