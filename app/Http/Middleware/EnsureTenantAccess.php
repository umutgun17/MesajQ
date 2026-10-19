<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    /**
     * Kullanıcının bir tenant'a bağlı olduğunu doğrular.
     * Super admin'ler (tenant_id = null) geçebilir.
     * Tenant'ı aktif olmayan kullanıcılar reddedilir.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Kimlik doğrulama gerekli.'], 401);
        }

        // Super admin her yere erişebilir
        if ($user->isSuperAdmin()) {
            // X-Tenant-Id header varsa aktif tenant'a ait olduğunu doğrula
            if ($request->hasHeader('X-Tenant-Id')) {
                $tenantId = (int) $request->header('X-Tenant-Id');
                $tenant = \App\Models\Tenant::find($tenantId);
                if (!$tenant || !$tenant->is_active) {
                    return response()->json(['message' => 'Geçersiz veya pasif tenant.'], 422);
                }
            }
            return $next($request);
        }

        // Tenant'ı olmayan normal kullanıcı
        if (! $user->tenant_id) {
            return response()->json(['message' => 'Hesabınız bir firmaya bağlı değil.'], 403);
        }

        // Tenant aktif mi? Pasife alınan firmanın açık oturumları da düşürülür:
        // token'lar iptal → sonraki istek 401 → SPA otomatik çıkışa gider.
        $tenant = $user->tenant;
        if (! $tenant || ! $tenant->is_active) {
            $user->tokens()->delete();

            return response()->json(['message' => 'Firma hesabınız askıya alınmış.'], 403);
        }

        // Kullanıcı aktif mi?
        if ($user->status !== 'active') {
            return response()->json(['message' => 'Hesabınız devre dışı bırakılmış.'], 403);
        }

        return $next($request);
    }
}
