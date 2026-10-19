<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trendyol Marketplace API Configuration
    |--------------------------------------------------------------------------
    */

    'seller_id'  => env('TRENDYOL_SELLER_ID'),
    'api_key'    => env('TRENDYOL_API_KEY'),
    'api_secret' => env('TRENDYOL_API_SECRET'),

    'base_url' => 'https://apigw.trendyol.com',

    'public_base_url' => env('TRENDYOL_PUBLIC_BASE_URL', 'https://apigw.trendyol.com'),
    'review_endpoint' => '/discovery-web-socialgw-service/api/review/{contentId}',

];
