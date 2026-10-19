<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * VAPI Tool Call endpoint.
 * VAPI sends tool calls here during live voice conversations.
 * Single endpoint dispatches based on function name.
 */
class VapiController extends Controller
{
    public function __construct(protected VapiService $vapi) {}

    public function handleToolCall(Request $request): JsonResponse
    {
        // Auth: VAPI server secret
        if (! $this->authenticateVapi($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Çok-tenant: assistantId → tenant eşlemesi (ayarsızsa env varsayılanı)
        $this->vapi->resolveTenantFromRequest($request);

        $message = $request->input('message', []);

        // VAPI sends: { "message": { "type": "tool-calls", "toolCallList": [...] } }
        if (($message['type'] ?? '') !== 'tool-calls') {
            return response()->json(['error' => 'Unsupported message type'], 400);
        }

        $toolCalls = $message['toolCallList'] ?? [];
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall['function']['name'] ?? '';
            $arguments = $toolCall['function']['arguments'] ?? [];
            $toolCallId = $toolCall['id'] ?? '';

            // VAPI bazen arguments'ı JSON string olarak gönderir
            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : [];
            }

            Log::info('VAPI tool-call', [
                'function' => $functionName,
                'arguments' => $arguments,
                'toolCallId' => $toolCallId,
            ]);

            try {
                $result = match ($functionName) {
                    'customer-lookup', 'customerLookup', 'customer_lookup' => $this->customerLookup($arguments),
                    'order-lookup', 'orderLookup', 'order_lookup' => $this->orderLookup($arguments),
                    'product-search', 'productSearch', 'product_search' => $this->productSearch($arguments),
                    'rag-query', 'ragQuery', 'rag_query' => $this->ragQuery($arguments),
                    default => 'Bilinmeyen araç: ' . $functionName,
                };
            } catch (\Throwable $e) {
                Log::error('VAPI tool-call error', [
                    'function' => $functionName,
                    'error' => $e->getMessage(),
                ]);
                $result = 'Bir hata oluştu, lütfen tekrar deneyin.';
            }

            $results[] = [
                'toolCallId' => $toolCallId,
                'result' => is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : $result,
            ];
        }

        return response()->json(['results' => $results]);
    }

    protected function customerLookup(array $args): array|string
    {
        // Telefon numarasını farklı parametre isimlerinden al
        $phone = $args['phone'] ?? $args['phone_number'] ?? $args['phoneNumber'] ?? $args['telefon'] ?? '';
        if (empty($phone)) {
            return 'Telefon numarası gerekli. Müşterinin telefon numarasını sorun.';
        }

        $result = $this->vapi->lookupCustomerByPhone($phone);

        if (! $result) {
            return json_encode([
                'found' => false,
                'message' => 'Bu telefon numarasına ait müşteri bulunamadı. Numarayı tekrar kontrol edin.',
            ], JSON_UNESCAPED_UNICODE);
        }

        return $result;
    }

    protected function orderLookup(array $args): array|string
    {
        $orderNo = $args['order_no'] ?? $args['orderNo'] ?? $args['order_number'] ?? $args['siparis_no'] ?? $args['siparisNo'] ?? '';
        if (empty($orderNo)) {
            return 'Sipariş numarası gerekli. Müşteriden sipariş numarasını isteyin.';
        }

        $result = $this->vapi->lookupOrder($orderNo);

        if (! $result) {
            return json_encode([
                'found' => false,
                'message' => "'{$orderNo}' numaralı sipariş bulunamadı. Sipariş numarasını tekrar kontrol edin, genelde TB ile başlar.",
            ], JSON_UNESCAPED_UNICODE);
        }

        return $result;
    }

    protected function productSearch(array $args): array|string
    {
        $query = $args['query'] ?? $args['name'] ?? $args['product_name'] ?? $args['urun'] ?? '';
        $barcode = $args['barcode'] ?? $args['barkod'] ?? $args['sku'] ?? '';

        if (empty($query) && empty($barcode)) {
            return 'Ürün adı, barkod veya SKU kodu gerekli.';
        }

        return $this->vapi->searchProducts($query, $barcode);
    }

    protected function ragQuery(array $args): string
    {
        $question = $args['question'] ?? $args['soru'] ?? $args['query'] ?? '';
        if (empty($question)) {
            return 'Soru gerekli.';
        }

        $context = $this->vapi->queryRag($question);

        return $context ?: 'Bu konuda bilgi bulunamadı. Müşteriden daha detaylı bilgi isteyin.';
    }

    protected function authenticateVapi(Request $request): bool
    {
        // VAPI may send secret in various formats
        $candidates = [
            $request->header('x-vapi-secret'),
            $request->header('server-secret'),
            $request->header('authorization'),
        ];

        foreach ($candidates as $value) {
            if (empty($value)) continue;

            // Strip "Bearer " prefix if present
            $token = preg_replace('/^Bearer\s+/i', '', $value);

            if ($this->vapi->validateServerSecret($token)) {
                return true;
            }
        }

        // Log failed auth for debugging
        Log::warning('VAPI auth failed', [
            'headers' => collect($request->headers->all())->except(['authorization', 'x-vapi-secret', 'server-secret', 'cookie'])->map(fn($v) => $v[0] ?? '')->filter()->toArray(),
        ]);

        return false;
    }
}
