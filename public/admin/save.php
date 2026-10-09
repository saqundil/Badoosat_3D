<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\HeroSlideService;
use App\Service\ValidationException;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Response;

Auth::require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('POST only.', 405);
}

$payload = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')
    ? Response::jsonBody()
    : $_POST;

Csrf::require($payload);

$service = HeroSlideService::make();

switch ((string) ($payload['action'] ?? 'save')) {
    case 'reorder':
        $ids = $payload['ids'] ?? [];

        if (!is_array($ids) || $ids === []) {
            Response::error('No order was supplied.', 422);
        }

        $service->reorder($ids);
        Response::json(['ok' => true]);

        // no break

    case 'delete':
        $service->delete((int) ($payload['id'] ?? 0));
        Response::redirect('/admin/?deleted=1');

        // no break

    case 'toggle':
        $service->toggle((int) ($payload['id'] ?? 0));
        Response::redirect('/admin/?saved=1');

        // no break

    case 'save':
        try {
            $service->save($payload);
        } catch (ValidationException $e) {
            Csrf::session();
            $_SESSION['errors'] = $e->errors();
            $_SESSION['old']    = $payload;

            $id = (int) ($payload['id'] ?? 0);
            Response::redirect('/admin/edit.php' . ($id > 0 ? '?id=' . $id : ''));
        }

        Response::redirect('/admin/?saved=1');

        // no break

    default:
        Response::error('Unknown action.', 400);
}
