<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaOAuthController extends Controller
{
    protected string $graphUrl;

    protected string $graphVersion;

    /**
     * Scopes required for full Meta integration
     */
    protected array $scopes = [
        'pages_show_list',
        'pages_messaging',
        'pages_read_engagement',
        'pages_read_user_content',
        'pages_manage_metadata',
        // 8 Eki 2026: sayfa adına yorum cevaplama + yoruma özel yanıt (eksikti → FB yorum cevapları #200)
        'pages_manage_engagement',
        'instagram_basic',
        'instagram_manage_messages',
        'instagram_manage_comments',
        // Otomatik sosyal medya yönetimi (organik yayınlama)
        'instagram_content_publish',
        'pages_manage_posts',
        'whatsapp_business_management',
        'whatsapp_business_messaging',
    ];

    public function __construct()
    {
        $this->graphVersion = config('meta.graph_api_version', 'v21.0');
        $this->graphUrl = "https://graph.facebook.com/{$this->graphVersion}";
    }

    /**
     * Resolve Meta app credentials from tenant settings, falling back to .env.
     */
    protected function resolveMetaCredentials(?int $tenantId = null): array
    {
        $tenant = $tenantId ? Tenant::find($tenantId) : Tenant::where('is_active', true)->first();
        $settings = $tenant?->settings ?? [];

        return [
            'app_id' => ! empty($settings['meta_app_id']) ? $settings['meta_app_id'] : config('meta.app_id', ''),
            'app_secret' => ! empty($settings['meta_app_secret']) ? $settings['meta_app_secret'] : config('meta.app_secret', ''),
        ];
    }

    /**
     * Step 1: Generate Facebook OAuth URL and return it to the frontend
     *
     * The frontend will redirect the user to this URL.
     */
    public function redirect(Request $request): JsonResponse
    {
        $user = $request->user();

        // Resolve tenant: user's own tenant or super admin's selected tenant via header
        $tenantId = $user->tenant_id;
        if (! $tenantId && $request->hasHeader('X-Tenant-Id')) {
            $tenantId = (int) $request->header('X-Tenant-Id');
        }

        if (! $tenantId) {
            return response()->json([
                'message' => 'Lütfen önce bir tenant seçin. Super admin hesabıyla doğrudan OAuth başlatılamaz.',
            ], 422);
        }

        // Encrypt user/tenant info into state parameter for CSRF protection
        $state = Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'ts' => time(),
        ]));

        $meta = $this->resolveMetaCredentials($tenantId);

        $params = http_build_query([
            'client_id' => $meta['app_id'],
            'redirect_uri' => $this->callbackUrl(),
            'scope' => implode(',', $this->scopes),
            'response_type' => 'code',
            'state' => $state,
        ]);

        $url = "https://www.facebook.com/{$this->graphVersion}/dialog/oauth?{$params}";

        return response()->json(['url' => $url]);
    }

    /**
     * Step 2: Handle Facebook OAuth callback
     *
     * Facebook redirects here with ?code=... and ?state=...
     * This is a public endpoint (no Sanctum auth), uses state for verification.
     */
    public function callback(Request $request): RedirectResponse
    {
        $baseUrl = rtrim(config('app.url', ''), '/');
        $channelsPath = "{$baseUrl}/settings/channels";

        // Check for errors from Facebook
        if ($request->has('error')) {
            Log::warning('Meta OAuth: User denied permissions', [
                'error' => $request->input('error'),
                'error_reason' => $request->input('error_reason'),
                'error_description' => $request->input('error_description'),
            ]);

            return redirect("{$channelsPath}?meta_oauth=error&reason=denied");
        }

        $code = $request->input('code');
        $state = $request->input('state');

        if (! $code || ! $state) {
            return redirect("{$channelsPath}?meta_oauth=error&reason=missing_params");
        }

        // Decrypt and validate state
        try {
            $stateData = json_decode(Crypt::decryptString($state), true);
        } catch (\Exception $e) {
            Log::error('Meta OAuth: Invalid state parameter', ['error' => $e->getMessage()]);

            return redirect("{$channelsPath}?meta_oauth=error&reason=invalid_state");
        }

        // State must be less than 10 minutes old
        if (! isset($stateData['ts']) || time() - $stateData['ts'] > 600) {
            return redirect("{$channelsPath}?meta_oauth=error&reason=expired");
        }

        $tenantId = $stateData['tenant_id'] ?? null;

        // Step 2a: Exchange code for short-lived user access token
        $meta = $this->resolveMetaCredentials($tenantId);

        $tokenResponse = Http::get("{$this->graphUrl}/oauth/access_token", [
            'client_id' => $meta['app_id'],
            'client_secret' => $meta['app_secret'],
            'redirect_uri' => $this->callbackUrl(),
            'code' => $code,
        ]);

        if ($tokenResponse->failed()) {
            Log::error('Meta OAuth: Failed to exchange code for token', [
                'status' => $tokenResponse->status(),
                'body' => $tokenResponse->json(),
            ]);

            return redirect("{$channelsPath}?meta_oauth=error&reason=token_exchange");
        }

        $shortLivedToken = $tokenResponse->json('access_token');

        // Step 2b: Exchange for long-lived user access token (60 days)
        $longLivedResponse = Http::get("{$this->graphUrl}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $meta['app_id'],
            'client_secret' => $meta['app_secret'],
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if ($longLivedResponse->failed()) {
            Log::error('Meta OAuth: Failed to get long-lived token', [
                'status' => $longLivedResponse->status(),
                'body' => $longLivedResponse->json(),
            ]);

            return redirect("{$channelsPath}?meta_oauth=error&reason=long_lived_token");
        }

        $longLivedUserToken = $longLivedResponse->json('access_token');

        Log::warning('Meta OAuth: Token exchange successful', [
            'tenant_id' => $tenantId,
            'has_long_lived_token' => ! empty($longLivedUserToken),
            'purpose' => $stateData['purpose'] ?? 'channels',
        ]);

        // Kreatif Stüdyo katalog bağlantısı: token'ı tenant settings'e şifreli
        // yaz ve katalog sayfasına dön (sayfa/IG/WhatsApp akışına girmez).
        if (($stateData['purpose'] ?? '') === 'creative') {
            return $this->finishCreativeConnect($tenantId, $longLivedUserToken, $baseUrl);
        }

        // Debug: check token info and granted scopes
        $debugResponse = Http::get("{$this->graphUrl}/debug_token", [
            'input_token' => $longLivedUserToken,
            'access_token' => $meta['app_id'] . '|' . $meta['app_secret'],
        ]);

        // Güvenlik: access token / page token / PII log'a YAZILMAZ — sadece durum bilgisi.
        Log::warning('Meta OAuth: Token debug info', [
            'is_valid' => $debugResponse->json('data.is_valid'),
            'scopes' => $debugResponse->json('data.scopes'),
            'expires_at' => $debugResponse->json('data.expires_at'),
        ]);

        // Also try /me to see which user this token belongs to
        $meResponse = Http::withToken($longLivedUserToken)
            ->get("{$this->graphUrl}/me", [
                'fields' => 'id,name',
            ]);

        Log::warning('Meta OAuth: /me response', [
            'user_id' => $meResponse->json('id'),
        ]);

        // Step 2c: Get user's pages with page access tokens
        $pagesResponse = Http::withToken($longLivedUserToken)
            ->get("{$this->graphUrl}/me/accounts", [
                'fields' => 'id,name,access_token,instagram_business_account',
                'limit' => 100,
            ]);

        // Güvenlik: yanıt gövdesi page access token içerir — log'a YAZILMAZ.
        Log::warning('Meta OAuth: Pages API response', [
            'status' => $pagesResponse->status(),
            'page_count' => is_array($pagesResponse->json('data')) ? count($pagesResponse->json('data')) : 0,
        ]);

        $pages = $pagesResponse->json('data', []);

        // If me/accounts is empty, extract page IDs from granular_scopes and fetch directly
        if (empty($pages)) {
            $debugData = $debugResponse->json('data', []);
            $granularScopes = $debugData['granular_scopes'] ?? [];
            $pageIds = [];

            foreach ($granularScopes as $scope) {
                foreach ($scope['target_ids'] ?? [] as $targetId) {
                    $pageIds[$targetId] = true;
                }
            }

            // Filter to only Facebook page IDs (not IG business account IDs)
            // Try fetching each target as a page with access_token field
            foreach (array_keys($pageIds) as $targetId) {
                $pageResponse = Http::withToken($longLivedUserToken)
                    ->get("{$this->graphUrl}/{$targetId}", [
                        'fields' => 'id,name,access_token,instagram_business_account',
                    ]);

                if ($pageResponse->successful() && $pageResponse->json('access_token')) {
                    $pages[] = $pageResponse->json();
                    Log::warning('Meta OAuth: Found page via direct query', [
                        'page_id' => $targetId,
                        'page_name' => $pageResponse->json('name'),
                    ]);
                }
            }
        }

        if (empty($pages)) {
            Log::error('Meta OAuth: No pages found (both me/accounts and direct query failed)', [
                'status' => $pagesResponse->status(),
                'body' => $pagesResponse->json(),
            ]);

            return redirect("{$channelsPath}?meta_oauth=error&reason=no_pages");
        }

        // Use the first page (most common case — single page per business)
        $page = $pages[0];
        $pageId = $page['id'];
        $pageName = $page['name'] ?? 'Facebook Sayfası';
        $pageAccessToken = $page['access_token'];
        $igBusinessAccountId = $this->resolvePageIgId($page, $pageAccessToken);

        // Step 2d: Get WhatsApp Business Account info (if available).
        // Tüm WABA'lar toplanıp webhook'a abone edilir; kanala yazılacak olan
        // HEDEF FİRMAYA göre seçilir (ilk-bulunan DEĞİL — 25 Tem olayı).
        $wabas = $this->collectWabas($longLivedUserToken);
        [$wabaId, $waPhoneNumberId] = $this->chooseWabaForTenant($wabas, $tenantId);

        // Birden fazla sayfa/hesaba yetki verildiyse: hangi hesabın bu firmaya
        // bağlanacağını kullanıcı seçsin. Bağlamı kısa ömürlü önbelleğe alıp
        // Kanallar sayfasına "seçim" moduyla dön (jetonlar querystring'e KONMAZ).
        if (count($pages) > 1) {
            $pickKey = (string) Str::uuid();
            Cache::put("meta:oauth:pending:{$pickKey}", [
                'tenant_id' => $tenantId,
                'pages' => $pages,
                'user_access_token' => $longLivedUserToken,
                'waba_id' => $wabaId,
                'wa_phone_number_id' => $waPhoneNumberId,
                'wabas' => $wabas,
            ], now()->addMinutes(15));

            // Sayfa seçimi beklenirken bile diğer firmaların ölen token'larını onar.
            $this->healMetaChannelsAfterOAuth($longLivedUserToken, $tenantId, $pages);

            return redirect("{$channelsPath}?meta_oauth=choose&pick={$pickKey}");
        }

        // Step 2e: Save to channels
        $this->saveToChannels($tenantId, $pageAccessToken, $pageId, $igBusinessAccountId, $wabaId, $waPhoneNumberId, $longLivedUserToken);

        // Numara Cloud API'ye kayıtlı değilse otomatik register et.
        $this->ensureWaPhoneRegistered($longLivedUserToken, $tenantId, $waPhoneNumberId);

        // Güvenlik ağı: bu OAuth başka firmaların grant'ını düşürdüyse
        // yeni token'la onarılabilenleri onar, kalanları yüksek sesle logla.
        $this->healMetaChannelsAfterOAuth($longLivedUserToken, $tenantId, $pages);

        Log::info('Meta OAuth: Successfully connected', [
            'tenant_id' => $tenantId,
            'page_id' => $pageId,
            'page_name' => $pageName,
            'ig_business_account_id' => $igBusinessAccountId,
            'waba_id' => $wabaId,
            'wa_phone_number_id' => $waPhoneNumberId,
            'scopes' => $this->scopes,
        ]);

        $query = http_build_query([
            'meta_oauth' => 'success',
            'page_name' => $pageName,
        ]);

        return redirect("{$channelsPath}?{$query}");
    }

    /**
     * Save OAuth tokens and IDs to all Meta channel types
     */
    protected function saveToChannels(?int $tenantId, string $pageAccessToken, string $pageId, ?string $igBusinessAccountId, ?string $wabaId = null, ?string $waPhoneNumberId = null, ?string $userAccessToken = null): void
    {
        $metaChannelTypes = [
            'facebook-message' => ['page_id' => $pageId],
            'facebook-comment' => ['page_id' => $pageId],
            'instagram-message' => ['page_id' => $igBusinessAccountId],
            'instagram-comment' => ['page_id' => $igBusinessAccountId],
            'whatsapp' => [],
        ];

        foreach ($metaChannelTypes as $type => $settings) {
            $channel = Channel::withoutGlobalScopes()
                ->where('type', $type)
                ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
                ->first();

            if (! $channel) {
                continue;
            }

            // Merge credentials (keep existing, update token)
            $existingCreds = $channel->credentials ?? [];
            if (is_string($existingCreds)) {
                $existingCreds = json_decode($existingCreds, true) ?? [];
            }

            if ($type === 'whatsapp') {
                // WhatsApp uses access_token key (not page_access_token).
                // 25 Tem dersi: SAYFA token'ı WhatsApp uçlarında #10 veriyor —
                // Cloud API çağrıları USER token'la yapılmalı. Sayfa token'ı yedek
                // olarak page_access_token anahtarında saklanır (medya indirme:
                // lookaside.fbsbx.com da user token ister).
                $channel->credentials = array_merge($existingCreds, array_filter([
                    'access_token' => $userAccessToken ?: $pageAccessToken,
                    'page_access_token' => $pageAccessToken,
                    'user_access_token' => $userAccessToken,
                ]));

                // Save WhatsApp-specific settings
                if ($wabaId || $waPhoneNumberId) {
                    $existingSettings = $channel->settings ?? [];
                    if (is_string($existingSettings)) {
                        $existingSettings = json_decode($existingSettings, true) ?? [];
                    }
                    $waMerge = [];
                    // Don't overwrite a phone_number_id / WABA that is already configured and
                    // working — the auto-discovered data.0 may point at a different WABA/number.
                    if ($wabaId && empty($existingSettings['business_account_id'])) {
                        $waMerge['business_account_id'] = $wabaId;
                    }
                    if ($waPhoneNumberId && empty($existingSettings['phone_number_id'])) {
                        $waMerge['phone_number_id'] = $waPhoneNumberId;
                    }
                    $channel->settings = array_merge($existingSettings, $waMerge);
                }
            } else {
                $channel->credentials = array_merge($existingCreds, [
                    'page_access_token' => $pageAccessToken,
                ]);
            }

            // Merge settings (keep existing, update page_id)
            $settingsValue = $settings['page_id'] ?? null;
            if ($settingsValue) {
                $existingSettings = $channel->settings ?? [];
                if (is_string($existingSettings)) {
                    $existingSettings = json_decode($existingSettings, true) ?? [];
                }
                $channel->settings = array_merge($existingSettings, [
                    'page_id' => $settingsValue,
                ]);
            }

            $channel->save();
        }

        // Yeni jetonlar geldi: sosyal medya "yayın izni" ve "hesap kimliği"
        // önbelleklerini temizle ki panel eski sonucu göstermesin.
        if ($tenantId) {
            Cache::forget("social:publish-ready:{$tenantId}");
            Cache::forget("social:account-identity:{$tenantId}");
        }
    }

    /**
     * Bir sayfanın bağlı Instagram Business hesabı ID'sini çözer
     * (me/accounts inline döndürmezse açık sorgu ile).
     */
    protected function resolvePageIgId(array $page, string $pageAccessToken): ?string
    {
        $igId = $page['instagram_business_account']['id'] ?? null;
        if (! $igId && ! empty($page['id'])) {
            $resp = Http::withToken($pageAccessToken)
                ->get("{$this->graphUrl}/{$page['id']}", ['fields' => 'instagram_business_account']);
            if ($resp->successful()) {
                $igId = $resp->json('instagram_business_account.id');
            }
        }

        return $igId;
    }

    /**
     * Grant'taki TÜM WABA'ları toplar (business → owned WABA → telefonlar)
     * ve her birini app webhook'una abone eder (gelen mesaj için ŞART;
     * page token bunu yapamaz, user token gerekir).
     *
     * @return array<int, array{id:string,name:?string,business_name:?string,phones:array}>
     */
    protected function collectWabas(string $userToken): array
    {
        $wabas = [];

        $bizResponse = Http::withToken($userToken)
            ->get("{$this->graphUrl}/me/businesses", ['fields' => 'id,name', 'limit' => 25]);

        foreach ($bizResponse->json('data', []) as $biz) {
            $wabaResponse = Http::withToken($userToken)
                ->get("{$this->graphUrl}/{$biz['id']}/owned_whatsapp_business_accounts", [
                    'fields' => 'id,name',
                ]);

            foreach ($wabaResponse->json('data', []) as $waba) {
                $wabaId = $waba['id'] ?? null;
                if (! $wabaId) {
                    continue;
                }

                $subResponse = Http::withToken($userToken)
                    ->post("{$this->graphUrl}/{$wabaId}/subscribed_apps");
                Log::info('Meta OAuth: WABA subscribe attempt', [
                    'waba_id' => $wabaId,
                    'status' => $subResponse->status(),
                ]);

                $phoneResponse = Http::withToken($userToken)
                    ->get("{$this->graphUrl}/{$wabaId}/phone_numbers", [
                        'fields' => 'id,display_phone_number,verified_name',
                    ]);

                $wabas[] = [
                    'id' => $wabaId,
                    'name' => $waba['name'] ?? null,
                    'business_name' => $biz['name'] ?? null,
                    'phones' => $phoneResponse->json('data', []) ?: [],
                ];
            }
        }

        return $wabas;
    }

    /**
     * Aday WABA'lar içinden HEDEF FİRMAYA ait olanı seçer (25 Tem olayı:
     * ilk-bulunan WABA başka firmanın hattıydı). Sıra:
     *  1) kanalın settings.display_number'ı ile numara eşleşmesi,
     *  2) WABA/business/verified adının tenant adıyla eşleşmesi,
     *  3) telefonlu tek aday varsa o,
     *  4) hiçbiri → null+log (yanlış firmaya bağlamaktansa boş bırak).
     *
     * @return array{0:?string,1:?string} [wabaId, phoneNumberId]
     */
    protected function chooseWabaForTenant(array $wabas, ?int $tenantId): array
    {
        $withPhones = array_values(array_filter($wabas, fn ($w) => ! empty($w['phones'])));
        if (empty($withPhones)) {
            return [null, null];
        }

        $digits = fn (?string $s) => substr(preg_replace('/\D+/', '', (string) $s), -10);

        // 1) Kanalda kayıtlı görünen numara ile eşleştir
        $displayNumber = null;
        if ($tenantId) {
            $channel = Channel::withoutGlobalScopes()
                ->where('type', 'whatsapp')->where('tenant_id', $tenantId)->first();
            $displayNumber = $digits($channel->settings['display_number'] ?? null);
        }
        if ($displayNumber) {
            foreach ($withPhones as $waba) {
                foreach ($waba['phones'] as $phone) {
                    if ($digits($phone['display_phone_number'] ?? null) === $displayNumber) {
                        return [$waba['id'], $phone['id'] ?? null];
                    }
                }
            }
        }

        // 2) Tenant adı ile WABA/business/verified adı eşleşmesi
        $tenantName = $tenantId ? Str::lower(trim((string) (Tenant::find($tenantId)?->name))) : '';
        if ($tenantName !== '') {
            foreach ($withPhones as $waba) {
                $names = array_filter([
                    $waba['name'] ?? null,
                    $waba['business_name'] ?? null,
                    $waba['phones'][0]['verified_name'] ?? null,
                ]);
                foreach ($names as $name) {
                    $name = Str::lower(trim($name));
                    if ($name !== '' && (Str::contains($name, $tenantName) || Str::contains($tenantName, $name))) {
                        return [$waba['id'], $waba['phones'][0]['id'] ?? null];
                    }
                }
            }
        }

        // 3) Tek aday
        if (count($withPhones) === 1) {
            return [$withPhones[0]['id'], $withPhones[0]['phones'][0]['id'] ?? null];
        }

        Log::warning('Meta OAuth: WABA hedef firmayla eşleştirilemedi — kanala yazılmadı', [
            'tenant_id' => $tenantId,
            'candidates' => array_map(fn ($w) => [
                'id' => $w['id'],
                'name' => $w['name'],
                'phones' => array_column($w['phones'], 'display_phone_number'),
            ], $withPhones),
        ]);

        return [null, null];
    }

    /**
     * WhatsApp numarasını Cloud API'ye kaydeder (register). Numara WABA'ya
     * ekli ve VERIFIED/CONNECTED görünse bile bu adım yapılmadan mesaj alıp
     * GÖNDEREMEZ (25 Tem: Boise kayıtsızdı, gelen mesajlar Meta'ya hiç
     * ulaşmıyordu — WABA analytics sayaçları 0). PIN kanal credentials'ta
     * saklanır; numara zaten kayıtlıysa (is_pin_enabled) dokunulmaz.
     */
    protected function ensureWaPhoneRegistered(string $userToken, ?int $tenantId, ?string $phoneNumberId): void
    {
        if (! $phoneNumberId || ! $tenantId) {
            return;
        }

        try {
            $info = Http::get("{$this->graphUrl}/{$phoneNumberId}", [
                'fields' => 'is_pin_enabled,platform_type,status',
                'access_token' => $userToken,
            ])->json();

            if (($info['platform_type'] ?? '') === 'CLOUD_API' && ($info['is_pin_enabled'] ?? false)) {
                return; // zaten kayıtlı
            }

            $channel = Channel::withoutGlobalScopes()
                ->where('type', 'whatsapp')->where('tenant_id', $tenantId)->first();
            $creds = [];
            if ($channel) {
                $creds = is_string($channel->credentials)
                    ? (json_decode($channel->credentials, true) ?? [])
                    : ($channel->credentials ?? []);
            }
            $pin = (string) ($creds['wa_two_step_pin'] ?? random_int(100000, 999999));

            $resp = Http::withToken($userToken)->post("{$this->graphUrl}/{$phoneNumberId}/register", [
                'messaging_product' => 'whatsapp',
                'pin' => $pin,
            ]);

            if ($resp->successful()) {
                if ($channel) {
                    $channel->credentials = array_merge($creds, ['wa_two_step_pin' => $pin]);
                    $channel->save();
                }
                Log::warning('Meta OAuth: WhatsApp numarası Cloud API\'ye otomatik kaydedildi (register)', [
                    'tenant_id' => $tenantId,
                    'phone_number_id' => $phoneNumberId,
                    'pin_saved' => (bool) $channel,
                ]);
            } else {
                Log::error('Meta OAuth: WhatsApp Cloud API register BAŞARISIZ — numara mesaj alamaz/gönderemez. Numaranın mevcut 2 adımlı doğrulama PIN\'i farklıysa elle register gerekir.', [
                    'tenant_id' => $tenantId,
                    'phone_number_id' => $phoneNumberId,
                    'status' => $resp->status(),
                    'body' => $resp->json(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Meta OAuth: register denemesi hata verdi (akış etkilenmedi)', [
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * OAuth güvenlik ağı (25 Tem olayı — 3. tekrar): kullanıcı izin ekranında
     * tek firmayı seçince app'in DİĞER firmalardaki izinleri düşüyor ve onların
     * kanal token'ları ölüyor. Bu tarama tüm tenant'ların aktif Meta kanallarını
     * test eder; ölen token'ı yeni grant'tan onarabiliyorsa onarır, onaramıyorsa
     * ERROR loglar. OAuth akışını asla kırmaz.
     *
     * @param array $grantedPages callback'in elindeki /me/accounts listesi
     */
    protected function healMetaChannelsAfterOAuth(string $userToken, ?int $connectingTenantId, array $grantedPages = []): void
    {
        try {
            // Verilen sayfa listesi boşsa kendimiz çekelim
            if (empty($grantedPages)) {
                $grantedPages = Http::withToken($userToken)
                    ->get("{$this->graphUrl}/me/accounts", [
                        'fields' => 'id,name,access_token,instagram_business_account',
                        'limit' => 100,
                    ])->json('data', []);
            }

            // page_id VE bağlı IG id → sayfa token'ı haritası
            $tokenByPageId = [];
            foreach ($grantedPages as $p) {
                if (empty($p['id']) || empty($p['access_token'])) {
                    continue;
                }
                $tokenByPageId[(string) $p['id']] = $p['access_token'];
                $igId = $p['instagram_business_account']['id']
                    ?? Http::withToken($p['access_token'])
                        ->get("{$this->graphUrl}/{$p['id']}", ['fields' => 'instagram_business_account'])
                        ->json('instagram_business_account.id');
                if ($igId) {
                    $tokenByPageId[(string) $igId] = $p['access_token'];
                }
            }

            $metaTypes = ['facebook-message', 'facebook-comment', 'instagram-message', 'instagram-comment', 'whatsapp'];
            $channels = Channel::withoutGlobalScopes()
                ->whereIn('type', $metaTypes)->where('is_active', true)->get();

            foreach ($channels as $channel) {
                $creds = is_string($channel->credentials)
                    ? (json_decode($channel->credentials, true) ?? [])
                    : ($channel->credentials ?? []);
                $settings = is_string($channel->settings)
                    ? (json_decode($channel->settings, true) ?? [])
                    : ($channel->settings ?? []);

                if ($channel->type === 'whatsapp') {
                    $token = $creds['access_token'] ?? null;
                    $pnid = $settings['phone_number_id'] ?? null;
                    if (! $token || ! $pnid) {
                        continue;
                    }
                    $alive = Http::get("{$this->graphUrl}/{$pnid}", [
                        'fields' => 'id', 'access_token' => $token,
                    ])->successful();
                    if ($alive) {
                        continue;
                    }
                    // Onarım: user token bu numaraya erişebiliyorsa onu yaz
                    $healable = Http::get("{$this->graphUrl}/{$pnid}", [
                        'fields' => 'id', 'access_token' => $userToken,
                    ])->successful();
                    if ($healable) {
                        $channel->credentials = array_merge($creds, [
                            'access_token' => $userToken,
                            'user_access_token' => $userToken,
                        ]);
                        $channel->save();
                        Log::warning('Meta OAuth güvenlik ağı: WhatsApp kanal token onarıldı', [
                            'channel_id' => $channel->id, 'tenant_id' => $channel->tenant_id,
                        ]);
                    } else {
                        Log::error('Meta OAuth güvenlik ağı: WhatsApp kanal token ÖLÜ ve onarılamadı — bu firma izin ekranında seçilmemiş olabilir, tam-varlık re-OAuth gerekir', [
                            'channel_id' => $channel->id, 'tenant_id' => $channel->tenant_id,
                        ]);
                    }
                    continue;
                }

                $token = $creds['page_access_token'] ?? $creds['access_token'] ?? null;
                if (! $token) {
                    continue;
                }
                $alive = Http::get("{$this->graphUrl}/me", ['access_token' => $token])->successful();
                if ($alive) {
                    continue;
                }
                $pageId = (string) ($settings['page_id'] ?? '');
                if ($pageId !== '' && isset($tokenByPageId[$pageId])) {
                    $channel->credentials = array_merge($creds, [
                        'page_access_token' => $tokenByPageId[$pageId],
                    ]);
                    $channel->save();
                    Log::warning('Meta OAuth güvenlik ağı: sayfa kanal token onarıldı', [
                        'channel_id' => $channel->id, 'tenant_id' => $channel->tenant_id,
                        'type' => $channel->type, 'page_id' => $pageId,
                    ]);
                } else {
                    Log::error('Meta OAuth güvenlik ağı: kanal token ÖLÜ ve yeni grant sayfayı içermiyor — tam-varlık re-OAuth gerekir', [
                        'channel_id' => $channel->id, 'tenant_id' => $channel->tenant_id,
                        'type' => $channel->type, 'page_id' => $pageId,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Meta OAuth güvenlik ağı taraması başarısız (akış etkilenmedi)', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Çok-sayfa seçimi: bekleyen bağlantının güvenli sayfa listesini döndürür
     * (isim + id + IG var mı — JETON İÇERMEZ).
     */
    public function pendingPages(Request $request, string $key): JsonResponse
    {
        $pending = Cache::get("meta:oauth:pending:{$key}");
        if (! $pending) {
            return response()->json(['message' => 'Seçim süresi doldu. Lütfen Meta bağlantısını tekrar başlatın.'], 410);
        }

        if (! $this->userOwnsTenant($request, $pending['tenant_id'])) {
            return response()->json(['message' => 'Bu bağlantı seçimi için yetkiniz yok.'], 403);
        }

        $pages = array_map(fn ($p) => [
            'id' => $p['id'],
            'name' => $p['name'] ?? 'Facebook Sayfası',
            'has_instagram' => ! empty($p['instagram_business_account']['id']),
        ], $pending['pages']);

        return response()->json(['pages' => $pages]);
    }

    /**
     * Çok-sayfa seçimi: kullanıcının seçtiği sayfayı bu firmaya bağlar.
     */
    public function selectPage(Request $request, string $key): JsonResponse
    {
        $pending = Cache::get("meta:oauth:pending:{$key}");
        if (! $pending) {
            return response()->json(['message' => 'Seçim süresi doldu. Lütfen Meta bağlantısını tekrar başlatın.'], 410);
        }

        if (! $this->userOwnsTenant($request, $pending['tenant_id'])) {
            return response()->json(['message' => 'Bu bağlantı seçimi için yetkiniz yok.'], 403);
        }

        $pageId = (string) $request->input('page_id');
        $page = collect($pending['pages'])->firstWhere('id', $pageId);
        if (! $page) {
            return response()->json(['message' => 'Seçilen sayfa bulunamadı.'], 404);
        }

        $igBusinessAccountId = $this->resolvePageIgId($page, $page['access_token']);

        // WABA'yı hedef firmaya göre yeniden seç (eski akışın ilk-bulunan değeri
        // yalnızca aday listesi yoksa kullanılır).
        $wabaId = $pending['waba_id'] ?? null;
        $waPhoneNumberId = $pending['wa_phone_number_id'] ?? null;
        if (! empty($pending['wabas'])) {
            [$wabaId, $waPhoneNumberId] = $this->chooseWabaForTenant($pending['wabas'], $pending['tenant_id']);
        }

        $this->saveToChannels(
            $pending['tenant_id'],
            $page['access_token'],
            $page['id'],
            $igBusinessAccountId,
            $wabaId,
            $waPhoneNumberId,
            $pending['user_access_token'] ?? null,
        );

        if (! empty($pending['user_access_token'])) {
            $this->ensureWaPhoneRegistered($pending['user_access_token'], $pending['tenant_id'], $waPhoneNumberId);
            $this->healMetaChannelsAfterOAuth($pending['user_access_token'], $pending['tenant_id'], $pending['pages']);
        }

        Cache::forget("meta:oauth:pending:{$key}");

        Log::info('Meta OAuth: Sayfa seçimi ile bağlandı', [
            'tenant_id' => $pending['tenant_id'],
            'page_id' => $page['id'],
            'ig_business_account_id' => $igBusinessAccountId,
        ]);

        return response()->json([
            'ok' => true,
            'page_name' => $page['name'] ?? 'Facebook Sayfası',
        ]);
    }

    /**
     * İstek sahibi kullanıcı verilen tenant'a erişebiliyor mu?
     * (kendi tenant'ı veya süper admin için X-Tenant-Id başlığı).
     */
    protected function userOwnsTenant(Request $request, ?int $tenantId): bool
    {
        $user = $request->user();
        if (! $user || ! $tenantId) {
            return false;
        }
        if ($user->tenant_id) {
            return (int) $user->tenant_id === (int) $tenantId;
        }

        // Süper admin: seçili firma başlıktan gelir.
        return $request->hasHeader('X-Tenant-Id')
            && (int) $request->header('X-Tenant-Id') === (int) $tenantId;
    }

    /**
     * Get the OAuth callback URL
     */
    protected function callbackUrl(): string
    {
        return rtrim(config('app.url'), '/') . '/api/meta/oauth/callback';
    }

    /** purpose=creative: kullanıcı token'ını Kreatif Stüdyo bağlantısı olarak kaydet. */
    protected function finishCreativeConnect(?int $tenantId, string $userToken, string $baseUrl): RedirectResponse
    {
        $catalogPath = "{$baseUrl}/creative/catalog";
        $tenant = $tenantId ? Tenant::find($tenantId) : null;
        if (! $tenant) {
            return redirect("{$catalogPath}?meta=error&reason=tenant");
        }

        $me = Http::withToken($userToken)->get("{$this->graphUrl}/me", ['fields' => 'id,name']);

        $settings = $tenant->settings ?? [];
        $settings['creative_meta'] = array_merge($settings['creative_meta'] ?? [], [
            'user_token' => Crypt::encryptString($userToken),
            'user_id' => $me->json('id'),
            'user_name' => $me->json('name'),
            'connected_at' => now()->toIso8601String(),
        ]);
        $tenant->update(['settings' => $settings]);

        Log::warning('Meta OAuth: Kreatif Stüdyo katalog bağlantısı kaydedildi', [
            'tenant_id' => $tenant->id,
            'meta_user' => $me->json('name'),
        ]);

        return redirect("{$catalogPath}?meta=connected");
    }
}
