<?php

use App\Http\Controllers\Site\SiteController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

// ── Tanıtım sitesi (sunucu tarafı Blade) ──
Route::get('/', [SiteController::class, 'home'])->name('site.home');
Route::get('/fiyatlandirma', [SiteController::class, 'pricing'])->name('site.pricing');
Route::get('/iletisim', [SiteController::class, 'contact'])->name('site.contact');
Route::get('/{page}', [SiteController::class, 'legal'])->where('page', 'kvkk|gizlilik|kullanim-sartlari|mesafeli-satis')->name('site.legal');

// ── Panel (React SPA) — /app/* ──
$spa = function () {
    if (request()->expectsJson()) {
        abort(404);
    }
    $indexPath = request()->is('iade', 'iade/*') && File::exists(public_path('app/iade.html'))
        ? public_path('app/iade.html')
        : public_path('app/index.html');
    if (File::exists($indexPath)) {
        return response(File::get($indexPath), 200, ['Content-Type' => 'text/html', 'Cache-Control' => 'no-cache, must-revalidate']);
    }

    return response('Frontend build not found. Run: cd frontend && npm run build', 404);
};
Route::get('/app', $spa);
Route::get('/app/{any}', $spa)->where('any', '.*');
// Public iade/iptal sayfası (müşteri tarafı, iframe) — yalnız iade modülü olan üründe anlamlı
Route::get('/iade/{any?}', $spa)->where('any', '.*');
// /public/... ile gelen yanlış adresleri köke taşı (ör. /public/app/ → /app/)
Route::get('/public/{any?}', fn (string $any = '') => redirect('/' . $any, 301))->where('any', '.*');
// Eski kök yolları panele taşı
Route::get('/login', fn () => redirect('/app/login'));
