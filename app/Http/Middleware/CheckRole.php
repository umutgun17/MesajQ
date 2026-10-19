<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Kullanıcının belirtilen rollerden birine sahip olduğunu doğrular.
     * Super admin tüm rol kontrollerini bypass eder.
     *
     * Kullanım: middleware('role:admin,moderator')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Kimlik doğrulama gerekli.'], 401);
        }

        // Super admin her role erişebilir
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (! in_array($user->role, $roles)) {
            return response()->json([
                'message' => 'Bu işlem için yetkiniz bulunmuyor.',
            ], 403);
        }

        return $next($request);
    }
}
