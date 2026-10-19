<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ticimax SOAP API Configuration
    |--------------------------------------------------------------------------
    */

    // Müşteri domaini config'e gömülmez: yalnız .env (tenant kanalları
    // kendi wsdl/auth değerlerini channel credentials'tan alır).
    'wsdl_url'  => env('TICIMAX_WSDL_URL'),
    'auth_code' => env('TICIMAX_AUTH_CODE'),

];
