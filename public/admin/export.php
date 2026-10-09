<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\OrderService;
use App\Service\ProductionExportService;
use App\Support\Auth;
use App\Support\Response;

Auth::require();

/*
 * Hands the shop floor a batch: the press files and one sheet saying what to
 * put each of them on.
 *
 * Either one order (?order=) or every order in a state (?status=), which in
 * practice means everything waiting to be produced.
 */

$orderIds = [];

if (isset($_GET['order'])) {
    $orderIds = [(int) $_GET['order']];
} else {
    $status = (string) ($_GET['status'] ?? 'in_production');
    $status = in_array($status, OrderService::STATUSES, true) ? $status : 'in_production';

    foreach (OrderService::make()->recent(200, $status) as $order) {
        $orderIds[] = (int) $order['id'];
    }
}

if ($orderIds === []) {
    Response::error('لا طلبات في هذه الدفعة.', 404);
}

try {
    $export = ProductionExportService::make()->build($orderIds);
} catch (Throwable $e) {
    Response::error($e->getMessage(), 500);
}

header('Content-Type: application/zip');
header('Content-Length: ' . filesize($export['path']));
header('Content-Disposition: attachment; filename="' . $export['name'] . '"');
header('X-Content-Type-Options: nosniff');
readfile($export['path']);

// The archive is a delivery, not a record: the orders it was built from are.
unlink($export['path']);
