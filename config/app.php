<?php

declare(strict_types=1);

use App\Support\Config;

return [
    'name'     => 'BADOOSAT — متجر التيشيرتات المطبوعة',
    'url'      => rtrim(Config::env('APP_URL') ?? 'http://127.0.0.1:8899', '/'),
    'debug'    => (bool) (Config::env('APP_DEBUG') ?? false),
    'timezone' => 'Asia/Amman',
    'locale'   => 'ar',

    'paths' => [
        'root'    => dirname(__DIR__),
        'public'  => dirname(__DIR__) . '/public',
        'uploads' => dirname(__DIR__) . '/public/uploads',
        'storage' => dirname(__DIR__) . '/storage',
        'cache'   => dirname(__DIR__) . '/storage/cache',
        'print'   => dirname(__DIR__) . '/storage/print',
        'logs'    => dirname(__DIR__) . '/storage/logs',
    ],

    'cache' => [
        // Swap for a Redis-backed store without touching any service.
        'driver' => 'file',
        'ttl'    => 3600,
    ],

    'uploads' => [
        'max_bytes'  => 15 * 1024 * 1024,
        'mime_types' => [
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg',
        ],
    ],

    // Shared secret for calling public/cron.php over HTTP. Empty means the URL
    // entry point is closed and only the command line can drain the queue.
    'cron_key' => Config::env('CRON_KEY', ''),

    'rate_limits' => [
        // action => [attempts, seconds]
        'upload'   => [20, 600],
        'checkout' => [10, 600],
        'login'    => [5, 900],
        // Order numbers are guessable in principle -- five characters from a
        // 32-letter alphabet -- so the page that turns one into an order is
        // the one place a script could walk. Ten tries per ten minutes makes
        // the search longer than the shop will be in business.
        'track'    => [10, 600],
    ],

    // Which PaymentDriverInterface implementation checkout routes through.
    'payment_driver' => Config::env('PAYMENT_DRIVER') ?? 'cod',

    'admin' => [
        'username'      => Config::env('ADMIN_USER') ?? 'admin',
        // php -r 'echo password_hash("...", PASSWORD_DEFAULT), PHP_EOL;'
        'password_hash' => Config::env('ADMIN_HASH') ?? '',

        // The languages the dashboard can be switched to with ?lang=. Each needs
        // a lang/<code>.php; the storefront's own locale is 'locale' above and
        // is not affected by this list.
        'locales' => ['ar', 'en'],

        // A variant at or under this many units counts as low stock on the
        // dashboard. Zero or less is out of stock.
        'low_stock_threshold' => 5,
    ],
];
