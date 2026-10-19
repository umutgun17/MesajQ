<?php

/**
 * MesajQ — SaaS ürün ailesi yapılandırması (marka, planlar, kayıt varsayılanları).
 * Fiyatlar PlanSeeder'da; burada marka/akış sabitleri.
 */
return [
    'site_maintenance' => (bool) env('SITE_MAINTENANCE', false),
    'family' => 'mesajq',
    'app_base' => env('SAAS_APP_BASE', rtrim(env('APP_URL', 'https://mesajq.com'), '/') . '/app'),

    'brand' => [
        'name' => 'MesajQ',
        'tagline' => 'Tüm müşteri mesajları tek gelen kutusunda',
        'domain' => 'mesajq.com',
        'legal_name' => env('SAAS_LEGAL_NAME', 'MesajQ'),
        'support_email' => env('SAAS_SUPPORT_EMAIL', 'destek@mesajq.com'),
        'color' => '#4f46e5',
    ],

    // Bu ürün ailesinin kapsadığı Tenant::PRODUCTS anahtarları (plan dışı anahtarlar reddedilir)
    'products' => ['inbox', 'chatbot'],

    'billing' => [
        'trial_days' => (int) env('SAAS_TRIAL_DAYS', 14),
        'grace_days' => (int) env('SAAS_GRACE_DAYS', 7),
        'tax_rate' => (float) env('SAAS_TAX_RATE', 20),
        'yearly_discount' => (float) env('SAAS_YEARLY_DISCOUNT', 0.25),
        'invoice_prefix' => 'MQ',
        'bank_transfer' => [
            'enabled' => (bool) env('SAAS_BANK_TRANSFER', true),
            'bank' => env('SAAS_BANK_NAME', ''),
            'iban' => env('SAAS_BANK_IBAN', ''),
            'holder' => env('SAAS_BANK_HOLDER', ''),
            'note' => 'Açıklamaya fatura numaranızı yazın; ödeme aynı iş günü hesabınıza yansıtılır.',
        ],
    ],

    'iyzico' => [
        'api_key' => env('IYZICO_API_KEY', ''),
        'secret_key' => env('IYZICO_SECRET_KEY', ''),
        'base_url' => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
    ],

    // Kullanım sayaçları (plan limitleri bu anahtarlarla eşleşir)
    'metrics' => [
        'ai_messages' => 'AI destekli mesaj',
        'conversations' => 'Konuşma',
        'chat_sessions' => 'Web chat oturumu',
    ],

    // Kayıtta açılan (pasif) kanallar — müşteri kurulumda bağlar
    'default_channels' => [
        ['type' => 'whatsapp', 'name' => 'WhatsApp', 'category' => 'Gelen Mesaj'],
        ['type' => 'instagram-message', 'name' => 'Instagram', 'category' => 'Gelen Mesaj'],
        ['type' => 'facebook-message', 'name' => 'Facebook Messenger', 'category' => 'Gelen Mesaj'],
        ['type' => 'instagram-comment', 'name' => 'Instagram', 'category' => 'Yorum'],
        ['type' => 'facebook-comment', 'name' => 'Facebook', 'category' => 'Yorum'],
        ['type' => 'email', 'name' => 'E-posta', 'category' => 'E-posta'],
        ['type' => 'trendyol-product', 'name' => 'Trendyol', 'category' => 'Ürün Sorusu'],
        ['type' => 'trendyol-order', 'name' => 'Trendyol', 'category' => 'Sipariş Sorusu'],
        ['type' => 'hepsiburada-product', 'name' => 'Hepsiburada', 'category' => 'Ürün Sorusu'],
        ['type' => 'hepsiburada-order', 'name' => 'Hepsiburada', 'category' => 'Sipariş Sorusu'],
    ],

    // Kurulum sihirbazı adımları
    'onboarding' => [
        ['key' => 'verify', 'check' => 'email_verified', 'title' => 'E-postanı doğrula', 'desc' => 'Gelen kutundaki doğrulama bağlantısına tıkla.', 'to' => '/profile'],
        ['key' => 'channel', 'check' => 'channel_connected', 'title' => 'İlk kanalını bağla', 'desc' => 'WhatsApp, Instagram, Messenger, e-posta ya da pazaryeri soru kutusu.', 'to' => '/settings/channels'],
        ['key' => 'team', 'check' => 'team_invited', 'title' => 'Ekibini davet et', 'desc' => 'Temsilcileri ekle, sayfa yetkilerini ver.', 'to' => '/settings/users'],
        ['key' => 'billing', 'check' => 'billing_profile', 'title' => 'Fatura bilgilerini gir', 'desc' => 'Deneme bitince kesintisiz devam için.', 'to' => '/billing'],
    ],
];
