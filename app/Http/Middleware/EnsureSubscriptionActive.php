<?php

namespace App\Http\Middleware;

use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abonelik kapısı: süresi dolmuş / kilitli tenant'ların API erişimi 402 ile kesilir.
 * Faturalama, oturum ve profil uçları her zaman açıktır (ödeme yapabilsin diye).
 * Süper admin muaf.
 */
class EnsureSubscriptionActive
{
    /** Her durumda açık kalan yol önekleri (api/ sonrası). */
    private const OPEN_PREFIXES = ['auth/', 'billing', 'onboarding/status', 'users/'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->isSuperAdmin()) {
            return $next($request);
        }

        $path = ltrim(preg_replace('#^api/#', '', $request->path()), '/');
        foreach (self::OPEN_PREFIXES as $p) {
            if (str_starts_with($path, $p)) {
                return $next($request);
            }
        }

        $tenantId = (int) $user->tenant_id;
        $state = Cache::remember("tenant:{$tenantId}:subscription", 60, function () use ($tenantId) {
            $sub = Subscription::where('tenant_id', $tenantId)->first();

            return ['exists' => (bool) $sub, 'access' => $sub ? $sub->grantsAccess() : true, 'status' => $sub?->status];
        });

        if ($state['exists'] && ! $state['access']) {
            return response()->json([
                'message' => 'Aboneliğiniz sona erdi. Devam etmek için bir plan seçin.',
                'code' => 'subscription_required',
                'status' => $state['status'],
            ], 402);
        }

        return $next($request);
    }
}
