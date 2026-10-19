<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Channel;
use App\Services\VapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * VAPI Webhook endpoint.
 * Receives call lifecycle events: status-update, end-of-call-report, etc.
 */
class VapiWebhookController extends Controller
{
    public function __construct(protected VapiService $vapi) {}

    public function handle(Request $request): JsonResponse
    {
        // Auth — check multiple header formats
        $authenticated = false;
        $candidates = [
            $request->header('x-vapi-secret'),
            $request->header('server-secret'),
            $request->header('authorization'),
        ];

        foreach ($candidates as $value) {
            if (empty($value)) continue;
            $token = preg_replace('/^Bearer\s+/i', '', $value);
            if ($this->vapi->validateServerSecret($token)) {
                $authenticated = true;
                break;
            }
        }

        if (! $authenticated) {
            Log::warning('VAPI webhook: invalid secret', [
                'headers' => collect($request->headers->all())->except(['authorization', 'x-vapi-secret', 'server-secret', 'cookie'])->map(fn($v) => $v[0] ?? '')->filter()->toArray(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Çok-tenant: assistantId → tenant eşlemesi (ayarsızsa env varsayılanı)
        $this->vapi->resolveTenantFromRequest($request);

        $message = $request->input('message', []);
        $type = $message['type'] ?? $request->input('type', '');

        Log::info('VAPI webhook received', ['type' => $type, 'tenant_id' => $this->vapi->getTenantId()]);

        return match ($type) {
            'status-update' => $this->handleStatusUpdate($message),
            'end-of-call-report' => $this->handleEndOfCallReport($request->all()),
            'assistant-request' => $this->handleAssistantRequest($request->all()),
            'tool-calls' => app(VapiController::class)->handleToolCall($request),
            default => response()->json(['ok' => true]),
        };
    }

    protected function handleStatusUpdate(array $message): JsonResponse
    {
        $status = $message['status'] ?? '';

        if ($status === 'in-progress') {
            $channelId = $this->getVapiChannelId();
            $this->vapi->createCallFromVapi($message, $channelId);
        }

        return response()->json(['ok' => true]);
    }

    protected function handleEndOfCallReport(array $payload): JsonResponse
    {
        $this->vapi->updateCallFromEndReport($payload['message'] ?? $payload);

        return response()->json(['ok' => true]);
    }

    protected function handleAssistantRequest(array $payload): JsonResponse
    {
        // Can return dynamic assistant config. For now, return empty (use dashboard config).
        return response()->json([]);
    }

    protected function getVapiChannelId(): int
    {
        $tenantId = $this->vapi->getTenantId();

        $channel = Channel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('type', 'vapi-voice')
            ->first();

        return $channel?->id ?? 0;
    }
}
