<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Response;
use App\Support\UploadException;
use App\Support\Uploader;

Auth::require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('POST only.', 405);
}

Csrf::require();

try {
    $stored = Uploader::make()->store($_FILES['image'] ?? [], 'hero');
} catch (UploadException $e) {
    Response::error($e->getMessage(), 422);
}

Response::json($stored);
