<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meta (Facebook / Instagram) API Configuration
    |--------------------------------------------------------------------------
    */

    'app_id'            => env('META_APP_ID'),
    'app_secret'        => env('META_APP_SECRET'),
    'page_access_token' => env('META_PAGE_ACCESS_TOKEN'),
    'verify_token'      => env('META_VERIFY_TOKEN', 'chatapp_meta_webhook_2024'),

    'instagram_app_id'     => env('INSTAGRAM_APP_ID'),
    'instagram_app_secret' => env('INSTAGRAM_APP_SECRET'),
    'instagram_account_id' => env('INSTAGRAM_BUSINESS_ACCOUNT_ID'),

    'graph_api_version' => 'v21.0',
    'graph_api_url'     => 'https://graph.facebook.com/v21.0',

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business Cloud API
    |--------------------------------------------------------------------------
    */

    'whatsapp_phone_number_id'       => env('WHATSAPP_PHONE_NUMBER_ID'),
    'whatsapp_business_account_id'   => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
    'whatsapp_access_token'          => env('WHATSAPP_ACCESS_TOKEN'),

];
