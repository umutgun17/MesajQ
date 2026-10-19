<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Sayfa bazlı yetki kontrolü yapar.
     * Super admin tüm izinleri bypass eder.
     *
     * Kullanım: middleware('permission:messages')
     */
    public function handle(Request $request, Closure $next, string $page): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Kimlik doğrulama gerekli.'], 401);
        }

        // Super admin bypass
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $user->hasPermission($page)) {
            return response()->json([
                'message' => 'Bu sayfa/işlem için yetkiniz bulunmuyor.',
            ], 403);
        }

        return $next($request);
    }
}
