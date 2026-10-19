<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'claude' => [
        'api_key' => env('CLAUDE_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-sonnet-4-5-20250929'),
        'max_tokens' => (int) env('CLAUDE_MAX_TOKENS', 1024),
    ],

    // Sosyal medya modülü: Higgsfield Cloud API (görsel/reels üretimi)
    'higgsfield' => [
        'key' => env('HIGGSFIELD_API_KEY'),
        'secret' => env('HIGGSFIELD_API_SECRET'),
    ],

    // Google API key (Drive public klasör okuma; servis hesabı yoksa yedek yol)
    'google' => [
        'api_key' => env('GOOGLE_API_KEY'),
    ],

    // Google Ads API — platform geneli OAuth istemcisi + geliştirici token'ı.
    // Tenant bazlı refresh token tenant settings.google_ads.refresh_token'da (Crypt'li) durur.
    'google_ads' => [
        'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        // Yunbi'nin MCC (yönetici) hesabı — tenant kendi kökünü seçerse o kullanılır
        'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
        'api_version' => env('GOOGLE_ADS_API_VERSION', 'v23'),
    ],

    // LLM harcama freni: paylaşımlı platform anahtarını kullanan bir kiracının aylık
    // varsayılan üst sınırı (USD). Kiracı settings.ai_budget_usd ile ezebilir; 0 = limitsiz.
    'ai' => [
        'shared_budget_usd' => (float) env('AI_SHARED_BUDGET_USD', 25),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'embedding_dimensions' => (int) env('OPENAI_EMBEDDING_DIMENSIONS', 1536),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
    ],

    'vapi' => [
        'server_secret' => env('VAPI_SERVER_SECRET'),
        'api_key' => env('VAPI_API_KEY'),
        'assistant_id' => env('VAPI_ASSISTANT_ID'),
        'tenant_id' => (int) env('VAPI_TENANT_ID', 1),
    ],

];
