<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Web Push (VAPID)
    |--------------------------------------------------------------------------
    | VAPID anahtarları gönderim sunucusunu (Yunbi) tanımlar. Tek çift yeterli;
    | tarayıcı abonelikleri (endpoint) cihaz/tenant bazlı saklanır.
    | Anahtar üretmek için: Minishlink\WebPush\VAPID::createVapidKeys()
    */
    'subject'     => env('VAPID_SUBJECT', 'mailto:destek@yunbi.ai'),
    'public_key'  => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
];
