<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    /**
     * Platform groups — maps platform key to channel types + fields
     *
     * Each field has:
     *   key      — unique form field name (sent from frontend)
     *   storeKey — actual DB column key (defaults to key if omitted)
     *   store    — 'credentials' or 'settings'
     *   label    — user-facing label
     *   type     — input type: text, password, url
     *   for      — which channel types receive this field
     *   info     — help text (shown on info icon click)
     *   helpUrl  — optional external link for more info
     */
    protected static array $platforms = [
        'meta' => [
            'name' => 'Meta',
            'channel_types' => ['facebook-message', 'instagram-message', 'facebook-comment', 'instagram-comment', 'whatsapp'],
            'oauth' => true,
            'fields' => [],
        ],
        'trendyol' => [
            'name' => 'Trendyol',
            'channel_types' => ['trendyol-product', 'trendyol-order'],
            'fields' => [
                [
                    'key' => 'seller_id',
                    'label' => 'Satıcı ID',
                    'type' => 'text',
                    'store' => 'credentials',
                    'for' => ['trendyol-product', 'trendyol-order'],
                    'info' => 'Trendyol Satıcı Paneli → Entegrasyon Bilgileri → API Hesabı sayfasında "Satıcı ID" olarak görünen sayısal değer.',
                    'helpUrl' => 'https://partner.trendyol.com/account/developer-settings',
                ],
                [
                    'key' => 'api_key',
                    'label' => 'API Key',
                    'type' => 'password',
                    'store' => 'credentials',
                    'for' => ['trendyol-product', 'trendyol-order'],
                    'info' => 'Trendyol Satıcı Paneli → Entegrasyon Bilgileri → API Hesabı sayfasında "API Kullanıcı Adı" olarak oluşturulur.',
                    'helpUrl' => 'https://partner.trendyol.com/account/developer-settings',
                ],
                [
                    'key' => 'api_secret',
                    'label' => 'API Secret',
                    'type' => 'password',
                    'store' => 'credentials',
                    'for' => ['trendyol-product', 'trendyol-order'],
                    'info' => 'Trendyol Satıcı Paneli → Entegrasyon Bilgileri → API Hesabı sayfasında "API Şifre" olarak oluşturulur.',
                    'helpUrl' => 'https://partner.trendyol.com/account/developer-settings',
                ],
                [
                    'key' => 'product_api_key',
                    'label' => 'Ürün API Key (2. anahtar)',
                    'type' => 'password',
                    'store' => 'credentials',
                    'for' => ['trendyol-product'],
                    'info' => 'İsteğe bağlı. Ürün/katalog yetkisi olan AYRI bir API anahtarı. Yalnızca Trendyol Kıyas katalog senkronu bu anahtarı kullanır; Soru-Cevap ve yorumlar yukarıdaki ana anahtarla çalışmaya devam eder. Boşsa ana anahtar kullanılır.',
                    'helpUrl' => 'https://partner.trendyol.com/account/developer-settings',
                ],
                [
                    'key' => 'product_api_secret',
                    'label' => 'Ürün API Secret (2. anahtar)',
                    'type' => 'password',
                    'store' => 'credentials',
                    'for' => ['trendyol-product'],
                    'info' => 'İsteğe bağlı. 2. anahtarın API Şifresi.',
                    'helpUrl' => 'https://partner.trendyol.com/account/developer-settings',
                ],
            ],
        ],
        'hepsiburada' => [
            'name' => 'Hepsiburada',
            'channel_types' => ['hepsiburada-product', 'hepsiburada-order'],
            'fields' => [
                [
                    'key' => 'merchant_id',
                    'label' => 'Merchant ID',
                    'type' => 'text',
                    'store' => 'credentials',
                    'for' => ['hepsiburada-product', 'hepsiburada-order'],
                    'info' => 'Hepsiburada Merchant Panel → Entegrasyon Merkezi\'nden temin edilen UUID formatında mağaza kimliği.',
                    'helpUrl' => 'https://merchant.hepsiburada.com/',
                ],
                [
                    'key' => 'api_key',
                    'label' => 'API Key (Kullanıcı Adı)',
                    'type' => 'password',
                    'store' => 'credentials',
                    'for' => ['hepsiburada-product', 'hepsiburada-order'],
                    'info' => 'Hepsiburada entegratör panelinden aldığınız entegratör kullanıcı adı. Basic Auth için kullanılır.',
                    'helpUrl' => 'https://merchant.hepsiburada.com/',
                ],
                [
                    'key' => 'api_secret',
                    'label' => 'API Secret (Servis Anahtarı)',
                    'type' => 'password',
                    'store' => 'credentials',
                    'for' => ['hepsiburada-product', 'hepsiburada-order'],
                    'info' => 'Hepsiburada entegratör panelinden aldığınız servis anahtarı. Basic Auth şifresi olarak kullanılır.',
                    'helpUrl' => 'https://merchant.hepsiburada.com/',
                ],
            ],
        ],
        'ticimax' => [
            'name' => 'Ticimax',
            'channel_types' => ['ticimax-ticket', 'ticimax-ecommerce'],
            'fields' => [
                [
                    'key' => 'wsdl_url',
                    'label' => 'WSDL URL',
                    'type' => 'url',
                    'store' => 'credentials',
                    'for' => ['ticimax-ticket', 'ticimax-ecommerce'],
                    'info' => 'Ticimax mağazanızın web servis adresi. Genellikle https://www.siteadi.com/Servis/CustomServis.svc?wsdl formatındadır.',
                ],
                [
                    'key' => 'auth_code',
                    'label' => 'WS Yetki Kodu',
                    'type' => 'password',
                    'store' => 'credentials',
                    'for' => ['ticimax-ticket', 'ticimax-ecommerce'],
                    'info' => 'Ticimax yönetim paneli → Ayarlar → Web Servis Ayarları sayfasından alınan yetki kodu (UyeKodu).',
                ],
            ],
        ],
        'ikas' => [
            'name' => 'ikas',
            'channel_types' => ['ikas-ecommerce'],
            'fields' => [
                [
                    'key' => 'store_name',
                    'label' => 'Mağaza Adı (myikas alt alan adı)',
                    'type' => 'text',
                    'store' => 'credentials',
                    'for' => ['ikas-ecommerce'],
                    'info' => 'ikas admin adresinizdeki mağaza adı: https://MAGAZA.myikas.com/admin → sadece MAGAZA kısmı.',
                    'helpUrl' => 'https://ikas.dev/docs/api/getting-started/authentication',
                ],
                [
                    'key' => 'client_id',
                    'label' => 'Client ID',
                    'type' => 'text',
                    'store' => 'credentials',
                    'for' => ['ikas-ecommerce'],
                    'info' => 'ikas admin → Uygulamalar → Uygulamalarım → Özel Uygulama Oluştur adımında üretilen Client ID.',
                ],
                [
                    'key' => 'client_secret',
                    'label' => 'Client Secret',
                    'type' => 'password',
                    'store' => 'credentials',
                    'for' => ['ikas-ecommerce'],
                    'info' => 'Aynı özel uygulamanın Client Secret değeri. Gerekli izinler: READ_PRODUCTS, READ_ORDERS, READ_CUSTOMERS, READ_INVENTORIES.',
                ],
            ],
        ],
        'netgsm' => [
            'name' => 'NetGSM',
            'channel_types' => ['netgsm-call'],
            'fields' => [
                [
                    'key' => 'netgsm_usercode',
                    'label' => 'Kullanıcı Kodu (usercode)',
                    'type' => 'text',
                    'store' => 'credentials',
                    'for' => ['netgsm-call'],
                    'info' => 'NetGSM panelindeki API kullanıcı kodunuz (genellikle santral numaranız).',
                    'helpUrl' => 'https://www.netgsm.com.tr/',
                ],
                [
                    'key' => 'netgsm_password',
                    'label' => 'API Şifresi',
                    'type' => 'password',
                    'store' => 'credentials',
                    'for' => ['netgsm-call'],
                    'info' => 'NetGSM panel → API erişim şifreniz. Çağrı kayıtları bu kimlikle çekilir.',
                ],
            ],
        ],
        'email' => [
            'name' => 'E-posta',
            'channel_types' => ['email'],
            'fields' => [
                [
                    'key' => 'imap_host',
                    'label' => 'IMAP Sunucu',
                    'type' => 'text',
                    'store' => 'credentials',
                    'for' => ['email'],
                    'info' => 'E-posta sunucunuzun IMAP adresi. Örnek: mail.tonnyblack.com.tr veya imap.gmail.com',
                ],
                [
                    'key' => 'imap_port',
                    'label' => 'IMAP Port',
                    'type' => 'text',
                    'store' => 'credentials',
                    'for' => ['email'],
                    'info' => 'IMAP bağlantı portu. SSL için genellikle 993, TLS için 143.',
                ],
                [
                    'key' => 'imap_encryption',
                    'label' => 'IMAP Şifreleme',
                    'type' => 'text',
                    'store' => 'credentials',
                    'for' => ['email'],
                    'info' => 'Şifreleme türü: ssl, tls veya none. Genellikle ssl önerilir.',
                ],
                [
                    'key' => 'smtp_host',
                    'label' => 'SMTP Sunucu',
                    'type' => 'text',
                    'store' => 'credentials',
                    'for' => ['email'],
                    'info' => 'E-posta göndermek için SMTP sunucu adresi. Örnek: mail.tonnyblack.com.tr veya smtp.gmail.com',
                ],
                [
                    'key' => 'smtp_port',
                    'label' => 'SMTP Port',
                    'type' => 'text',
                    'store' => 'credentials',
                    'for' => ['email'],
                    'info' => 'SMTP bağlantı portu. SSL için 465, TLS için 587.',
                ],
                [
                    'key' => 'smtp_encryption',
                    'label' => 'SMTP Şifreleme',
                    'type' => 'text',
                    'store' => 'credentials',
                    'for' => ['email'],
                    'info' => 'Şifreleme türü: ssl, tls veya none. Genellikle ssl önerilir.',
                ],
                [
                    'key' => 'email_address',
                    'label' => 'E-posta Adresi',
                    'type' => 'text',
                    'store' => 'credentials',
                    'for' => ['email'],
                    'info' => 'Bağlanılacak e-posta adresi. Örnek: info@tonnyblack.com.tr',
                ],
                [
                    'key' => 'email_password',
                    'label' => 'Şifre',
                    'type' => 'password',
                    'store' => 'credentials',
                    'for' => ['email'],
                    'info' => 'E-posta hesabının şifresi.',
                ],
            ],
        ],
    ];

    protected static array $credentialFields = [
        'trendyol-product' => [
            ['key' => 'seller_id', 'label' => 'Seller ID', 'type' => 'text'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
            ['key' => 'api_secret', 'label' => 'API Secret', 'type' => 'password'],
        ],
        'hepsiburada-product' => [
            ['key' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text'],
            ['key' => 'api_key', 'label' => 'API Key (Kullanıcı Adı)', 'type' => 'password'],
            ['key' => 'api_secret', 'label' => 'API Secret (Servis Anahtarı)', 'type' => 'password'],
        ],
        'ticimax-ticket' => [
            ['key' => 'wsdl_url', 'label' => 'WSDL URL', 'type' => 'url'],
            ['key' => 'auth_code', 'label' => 'Yetki Kodu (UyeKodu)', 'type' => 'password'],
        ],
        'ticimax-ecommerce' => [
            ['key' => 'wsdl_url', 'label' => 'WSDL URL', 'type' => 'url'],
            ['key' => 'auth_code', 'label' => 'Yetki Kodu (UyeKodu)', 'type' => 'password'],
        ],
        'facebook-message' => [],
        'instagram-message' => [],
        'facebook-comment' => [],
        'instagram-comment' => [],
        'whatsapp' => [],
        'email' => [
            ['key' => 'imap_host', 'label' => 'IMAP Sunucu', 'type' => 'text'],
            ['key' => 'imap_port', 'label' => 'IMAP Port', 'type' => 'text'],
            ['key' => 'imap_encryption', 'label' => 'IMAP Şifreleme', 'type' => 'text'],
            ['key' => 'smtp_host', 'label' => 'SMTP Sunucu', 'type' => 'text'],
            ['key' => 'smtp_port', 'label' => 'SMTP Port', 'type' => 'text'],
            ['key' => 'smtp_encryption', 'label' => 'SMTP Şifreleme', 'type' => 'text'],
            ['key' => 'email_address', 'label' => 'E-posta Adresi', 'type' => 'text'],
            ['key' => 'email_password', 'label' => 'Şifre', 'type' => 'password'],
        ],
    ];

    public function showPlatform(string $platform): JsonResponse
    {
        $config = self::$platforms[$platform] ?? null;

        if (! $config) {
            return response()->json(['message' => 'Platform bulunamadı.'], 404);
        }

        $channels = Channel::whereIn('type', $config['channel_types'])->get();

        // Check if credentials exist
        $isOAuth = ! empty($config['oauth']);
        $hasCredentials = $channels->contains(function ($ch) use ($config, $isOAuth) {
            // OAuth platforms: check for page_access_token or access_token in credentials
            if ($isOAuth) {
                $creds = $ch->credentials ?? [];
                return ! empty($creds['page_access_token']) || ! empty($creds['access_token']);
            }

            foreach ($config['fields'] as $field) {
                if (! in_array($ch->type, $field['for'])) {
                    continue;
                }
                $storeKey = $field['storeKey'] ?? $field['key'];
                $store = $field['store'] ?? 'credentials';
                $data = $store === 'settings' ? ($ch->settings ?? []) : ($ch->credentials ?? []);
                if (! empty($data[$storeKey])) {
                    return true;
                }
            }

            return false;
        });

        // Return fields for frontend (with info/helpUrl, without for/store internals)
        $fields = array_map(fn ($f) => array_filter([
            'key' => $f['key'],
            'label' => $f['label'],
            'type' => $f['type'],
            'info' => $f['info'] ?? null,
            'helpUrl' => $f['helpUrl'] ?? null,
        ], fn ($v) => $v !== null), $config['fields']);

        return response()->json([
            'platform' => $platform,
            'name' => $config['name'],
            'has_credentials' => $hasCredentials,
            'credential_fields' => array_values($fields),
        ]);
    }

    public function updatePlatformCredentials(Request $request, string $platform): JsonResponse
    {
        $config = self::$platforms[$platform] ?? null;

        if (! $config) {
            return response()->json(['message' => 'Platform bulunamadı.'], 404);
        }

        $request->validate([
            'credentials' => 'required|array',
        ]);

        $incoming = array_filter($request->input('credentials'), fn ($v) => $v !== '' && $v !== null);

        if (empty($incoming)) {
            return response()->json(['message' => 'En az bir değer giriniz.'], 422);
        }

        $channels = Channel::whereIn('type', $config['channel_types'])->get();

        // NetGSM: kanal kaydı yoksa ilk kayıtta oluştur (yeni tenant kurulumu)
        if ($channels->isEmpty() && $platform === 'netgsm') {
            $channels = collect([Channel::create([
                'name' => 'NetGSM Çağrı',
                'type' => 'netgsm-call',
                'is_active' => true,
            ])]);
        }

        foreach ($channels as $channel) {
            $credentialsMerge = [];
            $settingsMerge = [];

            foreach ($config['fields'] as $field) {
                // Skip if this field is not for this channel type
                if (! in_array($channel->type, $field['for'])) {
                    continue;
                }

                $formKey = $field['key'];
                if (! isset($incoming[$formKey])) {
                    continue;
                }

                $storeKey = $field['storeKey'] ?? $formKey;
                $store = $field['store'] ?? 'credentials';

                if ($store === 'settings') {
                    $settingsMerge[$storeKey] = $incoming[$formKey];
                } else {
                    $credentialsMerge[$storeKey] = $incoming[$formKey];
                }
            }

            $changed = false;

            if (! empty($credentialsMerge)) {
                $existing = $channel->credentials ?? [];
                if (is_string($existing)) {
                    $existing = json_decode($existing, true) ?? [];
                }
                $channel->credentials = array_merge($existing, $credentialsMerge);
                $changed = true;
            }

            if (! empty($settingsMerge)) {
                $existing = $channel->settings ?? [];
                if (is_string($existing)) {
                    $existing = json_decode($existing, true) ?? [];
                }
                $channel->settings = array_merge($existing, $settingsMerge);
                $changed = true;
            }

            if ($changed) {
                $channel->save();
            }
        }

        return response()->json([
            'message' => 'Platform kimlik bilgileri güncellendi.',
            'platform' => $platform,
        ]);
    }

    public function index(): JsonResponse
    {
        $channels = Channel::orderBy('name')
            ->get()
            ->map(fn ($ch) => [
                'id' => $ch->id,
                'type' => $ch->type,
                'name' => $ch->name,
                'category' => $ch->category,
                'is_active' => $ch->is_active,
                'has_credentials' => ! empty(array_filter($ch->credentials ?? [], fn ($v) => $v !== null && $v !== '')),
                'last_synced_at' => $ch->last_synced_at?->toISOString(),
                'settings' => $ch->settings,
            ]);

        return response()->json($channels);
    }

    public function show(Channel $channel): JsonResponse
    {
        return response()->json([
            'id' => $channel->id,
            'type' => $channel->type,
            'name' => $channel->name,
            'category' => $channel->category,
            'is_active' => $channel->is_active,
            'has_credentials' => ! empty(array_filter($channel->credentials ?? [], fn ($v) => $v !== null && $v !== '')),
            'credential_fields' => self::$credentialFields[$channel->type] ?? [],
            'settings' => $channel->settings,
            'last_synced_at' => $channel->last_synced_at?->toISOString(),
        ]);
    }

    public function update(Request $request, Channel $channel): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => 'sometimes|boolean',
            'credentials' => 'sometimes|nullable|array',
            'settings' => 'sometimes|nullable|array',
        ]);

        // Credentials: merge with existing (only overwrite provided keys, keep others)
        if (isset($validated['credentials'])) {
            $existing = $channel->credentials ?? [];
            // Handle legacy double-encoded JSON strings
            if (is_string($existing)) {
                $existing = json_decode($existing, true) ?? [];
            }
            $incoming = $validated['credentials'];

            // Remove empty values (user left field blank = don't overwrite)
            $incoming = array_filter($incoming, fn ($v) => $v !== '' && $v !== null);

            $validated['credentials'] = array_merge($existing, $incoming);
        }

        $channel->update($validated);

        return response()->json([
            'id' => $channel->id,
            'type' => $channel->type,
            'name' => $channel->name,
            'category' => $channel->category,
            'is_active' => $channel->is_active,
            'has_credentials' => ! empty(array_filter($channel->credentials ?? [], fn ($v) => $v !== null && $v !== '')),
            'last_synced_at' => $channel->last_synced_at?->toISOString(),
            'settings' => $channel->settings,
        ]);
    }
}
