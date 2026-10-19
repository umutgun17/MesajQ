<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Faturalama: iyzico istemcisi .env'den (IYZICO_API_KEY / IYZICO_SECRET_KEY / IYZICO_BASE_URL)
        $this->app->bind(\App\Services\Billing\IyzicoClient::class, fn () => \App\Services\Billing\IyzicoClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /* Panel içi hassas uçlar için İZOLE sayaçlar.
         * NOT: 'throttle:N,1' biçimi girişli kullanıcıda anahtarı SADECE user
         * id'den üretir — dış gruptaki throttle:120,1 ile AYNI sayacı paylaşır;
         * panel dakikada N'den fazla istek atınca içteki düşük limit anında 429
         * verir (Meta set import'unun "seçilemiyor" bug'ının kökü). Bu yüzden
         * route'a özgü isimli limiter + kendi anahtar öneki şart. */
        $userKey = fn (Request $r, string $name) => $name . ':' . ($r->user()?->id ?: $r->ip());
        RateLimiter::for('creative-import', fn (Request $r) => Limit::perMinute(10)->by($userKey($r, 'creative-import')));
        RateLimiter::for('creative-feed-import', fn (Request $r) => Limit::perMinute(6)->by($userKey($r, 'creative-feed-import')));
        RateLimiter::for('creative-preview', fn (Request $r) => Limit::perMinute(12)->by($userKey($r, 'creative-preview')));
        RateLimiter::for('ai-generate', fn (Request $r) => Limit::perMinute(20)->by($userKey($r, 'ai-generate')));

        // Widget rate limiting — per IP + tenant slug combination
        RateLimiter::for('widget', function (Request $request) {
            $slug = $request->route('slug') ?? 'unknown';

            return [
                // 60 requests per minute per IP per tenant
                Limit::perMinute(60)->by($request->ip() . ':' . $slug),
                // Global cap: 200 requests per minute per IP across all tenants
                Limit::perMinute(200)->by('widget:' . $request->ip()),
            ];
        });

        // Akıllı Arama widget'ı — as-you-type önizleme yazma anında art arda istek üretir
        // (debounce 280ms ≈ 3-4 istek/sn patlama); 60/dk paylaşılan NAT IP'lerde yetmez
        RateLimiter::for('search-widget', function (Request $request) {
            $slug = $request->route('slug') ?? 'unknown';

            return [
                Limit::perMinute(120)->by($request->ip() . ':search:' . $slug),
                Limit::perMinute(300)->by('search-widget:' . $request->ip()),
            ];
        });

        // AI Chatbot — config/poll GET'leri (5 sn polling) + genel tavan
        RateLimiter::for('chat-widget', function (Request $request) {
            $slug = $request->route('slug') ?? 'unknown';

            return [
                Limit::perMinute(60)->by($request->ip() . ':chat:' . $slug),
                Limit::perMinute(180)->by('chat-widget:' . $request->ip()),
            ];
        });

        // AI Chatbot mesaj gönderimi — LLM maliyeti olduğundan daha sıkı
        RateLimiter::for('chat-message', function (Request $request) {
            $slug = $request->route('slug') ?? 'unknown';

            return [
                Limit::perMinute(12)->by($request->ip() . ':chatmsg:' . $slug),
                Limit::perMinute(40)->by('chat-message:' . $request->ip()),
            ];
        });

        // İade modülü (public) genel limiti — IP + tenant
        RateLimiter::for('returns-public', function (Request $request) {
            $slug = $request->route('slug') ?? 'unknown';

            return [
                Limit::perMinute(30)->by($request->ip() . ':' . $slug),
                Limit::perMinute(120)->by('returns:' . $request->ip()),
            ];
        });

        // Çark çevirme — otomasyona karşı limit (IP + tenant slug). NOT: TR mobil operatörleri
        // CGNAT kullanır (yüzlerce kullanıcı aynı çıkış IP'sini paylaşır); 5/dk gerçek
        // kullanıcılara 429 döndürüyordu. Asıl koruma dedup (email/telefon/fp) + honeypot.
        RateLimiter::for('wheel-spin', function (Request $request) {
            $slug = $request->route('slug') ?? 'unknown';
            $campaign = $request->route('campaign') ?? 'unknown';

            return [
                Limit::perMinute(15)->by($request->ip() . ':' . $slug . ':' . $campaign),
                Limit::perMinute(60)->by('wheel-spin:' . $request->ip()),
            ];
        });

        // Sipariş sorgulama (lookup) — brute-force'a karşı SIKI limit (IP + order_no + tenant)
        RateLimiter::for('returns-lookup', function (Request $request) {
            $slug = $request->route('slug') ?? 'unknown';
            $orderNo = (string) $request->input('order_no', '');

            return [
                // Aynı sipariş no'ya tekrar tekrar farklı email/telefon denemesini engelle
                Limit::perMinute(5)->by($request->ip() . ':' . $slug . ':' . $orderNo),
                // IP başına genel tahminleme limiti
                Limit::perMinute(15)->by('returns-lookup:' . $request->ip()),
            ];
        });
    }
}
