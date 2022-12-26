<?php

return [
    'host' => env('EZPAY_HOST', 'https://cinv.ezpay.com.tw'),
    'merchant_id' => env('EZPAY_MERCHANT_ID', ''),
    'key' => env('EZPAY_KEY', ''),
    'iv' => env('EZPAY_IV', ''),
    'order_class_name' => env('ORDER_CLASS_NAME', 'App\Models\Order'),
];
