<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Repository\CustomDesignRepository;
use App\Service\CustomDesignService;
use App\Service\PrintFileService;
use App\Service\ValidationException;
use App\Support\Csrf;
use App\Support\Garment;
use App\Support\RateLimiter;
use App\Support\Response;
use App\Support\UploadException;

Csrf::session();

/*
 * Which cut is being designed on.
 *
 * The key names an entry in config/garments.php -- the measured geometry, not
 * the merchandising row -- and it decides the print rectangles, the working
 * canvas and, crucially, the exact pixel size store() demands of the uploaded
 * press file. Defaulting it silently was safe only while every cut shared one
 * mesh; the moment a second is measured, a design made on one and checked
 * against the other is a press file at the wrong size.
 *
 * An unknown key produces a garment that reports itself not customizable, and
 * every path below refuses it rather than falling back to a different cut.
 */
$garmentKey = trim((string) ($_REQUEST['garment'] ?? ''));
$garment = $garmentKey === '' ? Garment::make() : Garment::make($garmentKey);

if (!$garment->isCustomizable()) {
    Response::error('هذا النوع لا يقبل التصميم المخصّص.', 422);
}

/*
 * The design editor's one endpoint.
 *
 * GET  returns the garment geometry, and a saved design when given its token.
 * POST accepts what the editor produced and answers with a token the cart can
 *      use. Everything about money stays out of it: the cart prices the line
 *      from the catalogue when the token is redeemed.
 */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim((string) ($_GET['token'] ?? ''));

    if ($token === '') {
        Response::json(['garment' => $garment->toArray()]);
    }

    $design = CustomDesignService::make()->load($token);

    if ($design === null) {
        // Fall back to the plain upload record, which has no editor state.
        $row = CustomDesignRepository::make()->findByToken($token);

        if ($row === null) {
            Response::error('لم يُعثر على التصميم.', 404);
        }

        Response::json([
            'design'  => [
                'token'     => $row['token'],
                'path'      => $row['image_path'],
                'mime'      => $row['mime'],
                'width_px'  => (int) $row['width_px'],
                'height_px' => (int) $row['height_px'],
                'dpi'       => (int) $row['dpi'],
                'quality'   => $row['quality'],
                'placement' => $row['placement'],
            ],
            'check'   => PrintFileService::make()->inspect(
                (int) $row['width_px'],
                (int) $row['height_px'],
                $row['mime']
            ),
            'garment' => $garment->toArray(),
        ]);
    }

    Response::json(['design' => $design, 'garment' => $garment->toArray()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('GET or POST only.', 405);
}

Csrf::require();

if (!RateLimiter::make()->check('upload', RateLimiter::client())) {
    Response::error('محاولات كثيرة خلال وقت قصير. انتظر قليلًا.', 429);
}

try {
    $result = CustomDesignService::make($garment)->store($_POST, $_FILES);
} catch (ValidationException $e) {
    Response::error(implode(' ', $e->errors()), 422, ['errors' => $e->errors()]);
} catch (UploadException $e) {
    Response::error($e->getMessage(), 422);
}

Response::json($result);
