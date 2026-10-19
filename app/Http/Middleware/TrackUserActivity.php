<?php

namespace App\Http\Middleware;

use App\Services\UserActivityRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sayfa görüntüleme izi. Asıl istekte HİÇ iş yapmaz; kayıt terminate() içinde,
 * yanıt istemciye gönderildikten sonra alınır (LiteSpeed fastcgi_finish_request).
 * Impersonation token'ları (süper admin "kullanıcı gözünden") kayıt dışıdır.
 */
class TrackUserActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($response->getStatusCode() >= 400) {
            return;
        }
        $user = $request->user();
        if (! $user) {
            return;
        }
        $token = $user->currentAccessToken();
        if ($token && str_starts_with((string) ($token->name ?? ''), 'impersonation:')) {
            return;
        }

        UserActivityRecorder::recordPageView($user, $request);
    }
}
