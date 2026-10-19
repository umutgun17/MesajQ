<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMetaComment;
use App\Jobs\ProcessMetaMessage;
use App\Jobs\ProcessWhatsAppMessage;
use App\Jobs\ProcessWhatsAppStatus;
use App\Services\MetaService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Meta's known IP ranges for webhook delivery (IPv4 + IPv6).
     */
    private const META_IP_RANGES_V4 = [
        '102.132.96.0/19',
        '157.240.0.0/16',
        '173.252.64.0/18',
        '179.60.192.0/22',
        '185.60.216.0/22',
        '204.15.20.0/22',
        '31.13.24.0/21',
        '31.13.64.0/18',
        '45.64.40.0/22',
        '66.220.144.0/20',
        '69.63.176.0/20',
        '69.171.224.0/19',
    ];

    private const META_IP_RANGES_V6 = [
        '2a03:2880::/32',
        '2620:0:1c00::/40',
    ];

    public function __construct(
        protected MetaService $metaService,
        protected WhatsAppService $whatsAppService,
    ) {}

    /**
     * GET /api/webhook/meta — Meta webhook verification
     */
    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $result = $this->metaService->verifyWebhook($mode, $token, $challenge);

        if ($result !== false) {
            return response($result, 200);
        }

        Log::warning('Meta webhook verification failed', [
            'mode'  => $mode,
            'token' => $token,
        ]);

        return response('Forbidden', 403);
    }

    /**
     * POST /api/webhook/meta — Receive webhook events
     */
    public function handle(Request $request): JsonResponse
    {
        $payload   = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256', '');
        $clientIp  = $request->ip();

        // Security: Validate request origin
        $signatureValid = $signature && $this->metaService->validateSignature($payload, $signature);
        $ipValid = $this->isMetaIp($clientIp);

        if (! $signatureValid && ! $ipValid) {
            Log::warning('Meta webhook rejected: invalid signature and unknown IP', [
                'ip' => $clientIp,
                'has_signature' => ! empty($signature),
                'signature' => $signature ? substr($signature, 0, 20) . '...' : null,
                'payload_length' => strlen($payload),
                'object' => null,
            ]);

            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if (! $signatureValid && $ipValid) {
            Log::debug('Meta webhook: signature mismatch but IP verified', [
                'ip' => $clientIp,
            ]);
        }

        $data    = $request->all();
        $object  = $data['object'] ?? null;
        $entries = $data['entry'] ?? [];

        Log::info('Meta webhook received', [
            'object'    => $object,
            'entry_ids' => collect($entries)->pluck('id')->all(),
            'ip'        => $clientIp,
            'sig_ok'    => $signatureValid,
        ]);

        foreach ($entries as $entry) {
            // WhatsApp Business Account events
            if ($object === 'whatsapp_business_account') {
                $parsed = $this->whatsAppService->parseWebhookEntry($entry);
                if ($parsed) {
                    ProcessWhatsAppMessage::dispatch($parsed)->onQueue('default');
                }

                // Giden mesaj statüleri (sent/delivered/read/failed) — sadece
                // kendi kayıtlarımıza işlenir, müşteriye okundu bilgisi gitmez.
                foreach ($this->whatsAppService->parseWebhookStatuses($entry) as $statusEvent) {
                    ProcessWhatsAppStatus::dispatch($statusEvent)->onQueue('default');
                }

                // Mesajlaşma limiti / kalite değişimi → tenant limitini otomatik güncelle
                $this->handleWaQualityUpdate($entry);
                continue;
            }

            // Messaging events (Messenger / Instagram DM)
            if (isset($entry['messaging'])) {
                $parsed = $this->metaService->parseMessagingEvent($entry);
                if ($parsed) {
                    ProcessMetaMessage::dispatch($parsed, $object)->onQueue('default');
                }
            }

            // Feed/comment events
            if (isset($entry['changes'])) {
                $parsed = $this->metaService->parseCommentEvent($entry);
                if ($parsed) {
                    ProcessMetaComment::dispatch($parsed, $object)->onQueue('default');
                }
            }
        }

        // Return 200 immediately (Meta has 20s timeout)
        return response()->json(['status' => 'ok']);
    }

    /**
     * Check if an IP belongs to Meta's known IP ranges (IPv4 + IPv6).
     */
    private function isMetaIp(string $ip): bool
    {
        // IPv4 check
        $ipLong = ip2long($ip);
        if ($ipLong !== false) {
            foreach (self::META_IP_RANGES_V4 as $range) {
                [$subnet, $bits] = explode('/', $range);
                $subnetLong = ip2long($subnet);
                $mask = -1 << (32 - (int) $bits);

                if (($ipLong & $mask) === ($subnetLong & $mask)) {
                    return true;
                }
            }

            return false;
        }

        // IPv6 check
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        foreach (self::META_IP_RANGES_V6 as $range) {
            [$subnet, $bits] = explode('/', $range);
            $subnetBin = inet_pton($subnet);
            $bits = (int) $bits;

            // Build mask
            $mask = str_repeat("\xff", intdiv($bits, 8));
            if ($bits % 8) {
                $mask .= chr(0xff << (8 - ($bits % 8)));
            }
            $mask = str_pad($mask, 16, "\x00");

            if (($ipBin & $mask) === ($subnetBin & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * phone_number_quality_update webhook'u: Meta mesajlaşma limiti (tier)
     * değiştiğinde current_limit gelir → ilgili tenant'ın wa_daily_unique_limit
     * ayarı OTOMATİK güncellenir (limit API'den sorgulanamıyor, tek kaynak bu).
     */
    private function handleWaQualityUpdate(array $entry): void
    {
        $wabaId = (string) ($entry['id'] ?? '');

        foreach ($entry['changes'] ?? [] as $change) {
            if (($change['field'] ?? '') !== 'phone_number_quality_update') {
                continue;
            }
            $value = $change['value'] ?? [];
            $tierMap = [
                'TIER_50' => 50, 'TIER_250' => 250, 'TIER_1K' => 1000,
                'TIER_10K' => 10000, 'TIER_100K' => 100000, 'TIER_UNLIMITED' => 1000000,
            ];
            $tier = $value['current_limit'] ?? null;
            $event = $value['event'] ?? null;

            Log::warning('WhatsApp kalite/limit güncellemesi', [
                'waba' => $wabaId, 'event' => $event, 'current_limit' => $tier, 'value' => $value,
            ]);

            // WABA → tenant eşlemesi kanal ayarlarından
            $channel = \App\Models\Channel::withoutGlobalScopes()
                ->where('type', 'whatsapp')
                ->where('is_active', true)
                ->get()
                ->first(fn ($ch) => ($ch->settings['business_account_id'] ?? null) === $wabaId);
            if (! $channel) {
                continue;
            }

            $tenant = \App\Models\Tenant::find($channel->tenant_id);
            if (! $tenant) {
                continue;
            }

            // Kalite bekçisi: cron'u beklemeden ANINDA tepki — cache düşür,
            // Meta'dan taze kaliteyi oku; RED ise kampanyaları duraklat,
            // düzeldiyse bekçinin durdurduklarını devam ettir.
            \App\Services\Marketing\PhoneQualityGuard::forget($tenant->id);
            \App\Services\Marketing\PhoneQualityGuard::enforce($tenant->id, 'webhook:'.($event ?: 'quality_update'));

            if (! $tier || ! isset($tierMap[$tier])) {
                continue;
            }

            $s = $tenant->fresh()->settings ?? [];
            $old = $s['wa_daily_unique_limit'] ?? null;
            $s['wa_daily_unique_limit'] = $tierMap[$tier];
            $s['wa_limit_tier'] = $tier;
            $s['wa_limit_synced_at'] = now()->toIso8601String();
            $tenant->update(['settings' => $s]);

            Log::warning("WhatsApp limiti Meta webhook'undan güncellendi", [
                'tenant_id' => $tenant->id, 'old' => $old, 'new' => $tierMap[$tier], 'event' => $event,
            ]);
        }
    }
}
