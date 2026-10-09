<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\PrintFileService;
use App\Service\ValidationException;
use App\Support\Csrf;
use App\Support\RateLimiter;
use App\Support\Response;
use App\Support\UploadException;

Csrf::session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('POST only.', 405);
}

Csrf::require();

if (!RateLimiter::make()->check('upload', RateLimiter::client())) {
    Response::error('محاولات رفع كثيرة خلال وقت قصير. انتظر قليلًا.', 429);
}

try {
    $result = PrintFileService::make()->registerDesign(
        $_FILES['design'] ?? [],
        (string) ($_POST['placement'] ?? 'front')
    );
} catch (UploadException $e) {
    Response::error($e->getMessage(), 422);
} catch (ValidationException $e) {
    Response::error(implode(' ', $e->errors()), 422, ['errors' => $e->errors()]);
}

Response::json($result);
