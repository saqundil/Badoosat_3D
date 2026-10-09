<?php

declare(strict_types=1);

use App\Support\Config;

/**
 * SMTP, not mail(). On shared hosting mail() hands the message to a local
 * sendmail with no envelope sender the receiving side trusts, and the result
 * lands in spam or is dropped without a bounce. Authenticated SMTP through the
 * domain's own mailbox is the difference between an order confirmation being
 * read and the customer phoning to ask whether the order went through.
 */

return [
    // 'smtp' sends; 'log' writes the message to storage/logs/mail and returns
    // success, which is what the test suite and a developer machine want.
    'driver' => Config::env('MAIL_DRIVER') ?? 'log',

    'smtp' => [
        'host'       => Config::env('MAIL_HOST') ?? '',
        'port'       => (int) (Config::env('MAIL_PORT') ?? 587),
        // 'tls' for STARTTLS on 587, 'ssl' for implicit TLS on 465.
        'encryption' => Config::env('MAIL_ENCRYPTION') ?? 'tls',
        'username'   => Config::env('MAIL_USERNAME') ?? '',
        'password'   => Config::env('MAIL_PASSWORD') ?? '',
        'timeout'    => 15,
    ],

    'from' => [
        'address' => Config::env('MAIL_FROM') ?? 'orders@example.com',
        'name'    => Config::env('MAIL_FROM_NAME') ?? 'BADOOSAT',
    ],

    // Where a copy of every customer email goes. Empty disables it.
    'bcc' => Config::env('MAIL_BCC') ?? '',
];
