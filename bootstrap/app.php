<?php

use App\Http\Controllers\Api\PublicChatController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {

            // AI Chatbot — public storefront sohbet akışı (auth yok, slug ile tenant; HandleCors dışı)
            Route::prefix('w/{slug}/chat')
                ->middleware(['chat-cors', 'throttle:chat-widget'])
                ->group(function () {
                    Route::match(['GET', 'OPTIONS'], '/config', [PublicChatController::class, 'chatConfig']);
                    Route::match(['GET', 'OPTIONS'], '/messages', [PublicChatController::class, 'messages']);
                    Route::match(['POST', 'OPTIONS'], '/message', [PublicChatController::class, 'message'])
                        ->middleware('throttle:chat-message');
                    // Ürün kartı tıklaması — PublicSearchController::event miras (atıf cron'u yakalar)
                    Route::match(['POST', 'OPTIONS'], '/event', [PublicChatController::class, 'event']);
                });

            // E-posta açılma/tıklama takibi — public, auth yok
            Route::middleware('throttle:240,1')->group(function () {
            });

        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\EnsureTenantAccess::class,
            'track-activity' => \App\Http\Middleware\TrackUserActivity::class,
            'role' => \App\Http\Middleware\CheckRole::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'subscription' => \App\Http\Middleware\EnsureSubscriptionActive::class,
            'chat-cors' => \App\Http\Middleware\ChatWidgetCors::class,
        ]);

        $middleware->statefulApi();

        // Exclude webhook routes from CSRF/stateful verification
        $middleware->validateCsrfTokens(except: [
            'api/webhook/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
