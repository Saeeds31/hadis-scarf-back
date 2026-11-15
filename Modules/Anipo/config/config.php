<?php

return [
    'base_url'    => env('ANIPO_BASE_URL', 'https://adminpnx.anipo.ir/api/v1'),
    'token'       => env('ANIPO_TOKEN'),
    'timeout'     => env('ANIPO_TIMEOUT', 20),
    'queue'       => env('ANIPO_QUEUE', 'anipo'),

    'order_code_prefix' => env('ANIPO_ORDER_PREFIX', 'ORD'),

    // واحد پول در دیتابیس شما
    'money_unit' => env('SHOP_MONEY_UNIT', 'toman'), // toman | rial

    'default_item_weight_g'    => 0,
    'default_parcel_weight_g'  => 500,

    'poll_window_days' => 14,
];
