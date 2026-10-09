<?php

declare(strict_types=1);

/**
 * Copy to config/local.php and fill in. Values are merged by key over
 * config/*.php, so you only list what differs.
 *
 * This file exists because environment variables are not reliably available:
 * many shared hosts offer no way to set them, and PHP's `variables_order` often
 * omits E so getenv() comes back empty even when the shell set one. A file
 * always works. If your host does give you real environment variables, delete
 * this file and use them instead — the config falls back to them.
 */
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'tshirt_store',
        'user' => 'root',
        'pass' => '',
    ],

    'app' => [
        'debug'    => false,
        'cron_key' => 'change-me',
        'admin'    => [
            'username' => 'admin',
            // php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), PHP_EOL;'
            'password_hash' => '',
        ],
    ],

    // 'auto' picks the best available; force 'shell' on a VPS.
    'print' => [
        'driver' => 'auto',
    ],

    /*
     * Order confirmations go out over authenticated SMTP through the domain's
     * own mailbox. The default 'log' driver writes them to storage/logs/mail
     * and sends nothing, which is what a developer machine wants; set 'smtp'
     * and fill these in before launch, or customers will telephone to ask
     * whether their order went through.
     */
    'mail' => [
        'driver' => 'log',
        'smtp'   => [
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls',   // 'ssl' for implicit TLS on 465
            'username'   => '',
            'password'   => '',
        ],
        'from' => [
            'address' => 'orders@example.com',
            'name'    => 'متجر التيشيرتات المطبوعة',
        ],
    ],

    /*
     * Money. Shipping, the quantity discount tiers and what printing a
     * customer's own artwork adds. Everything the shop charges is computed
     * from here and the database, never from the browser.
     */
    'shop' => [
        'shipping' => [
            'flat_cents'      => 300,
            'free_over_cents' => 5000,
        ],
        'custom_surcharge_cents' => 500,
    ],
];
