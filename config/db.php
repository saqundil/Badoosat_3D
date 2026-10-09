<?php

declare(strict_types=1);

use App\Support\Config;

return [
    'host'    => Config::env('DB_HOST') ?? '127.0.0.1',
    'port'    => (int) (Config::env('DB_PORT') ?? 3306),
    'name'    => Config::env('DB_NAME') ?? 'tshirt_store',
    'user'    => Config::env('DB_USER') ?? 'root',
    'pass'    => Config::env('DB_PASS') ?? '',
    'charset' => 'utf8mb4',
];
