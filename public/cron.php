<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Service\OrderService;
use App\Service\PrintFileService;
use App\Support\Config;
use App\Support\Queue;
use App\Support\Response;

/**
 * Queue entry point.
 *
 * Shared hosting: call it once a minute from the panel's cron, either as a URL
 * with ?key=... or on the command line. It drains what it can inside a budget
 * and exits, so two runs never overlap on the same job — the claim is a
 * conditional UPDATE, not a flag set after reading.
 *
 * VPS: run `php public/cron.php --loop` under systemd instead. Nothing in the
 * services changes.
 */

$cli = PHP_SAPI === 'cli';

if (!$cli) {
    $expected = (string) Config::get('app.cron_key', '');
    $given    = (string) ($_GET['key'] ?? '');

    if ($expected === '' || !hash_equals($expected, $given)) {
        Response::error('Not authorised.', 403);
    }
}

$queue = Queue::make();

$handlers = [
    OrderService::PRINT_JOB => static function (array $payload): void {
        // One job per artwork, not per garment: ten shirts in five sizes
        // carrying the same design is one sheet on the press.
        PrintFileService::make()->produce(
            (int) $payload['custom_design_id'],
            isset($payload['order_id']) ? (int) $payload['order_id'] : null
        );
    },
];

$loop = $cli && in_array('--loop', $argv ?? [], true);

do {
    $result = $queue->work($handlers, $loop ? 25 : 50);

    $line = sprintf(
        '[%s] processed=%d failed=%d',
        date('Y-m-d H:i:s'),
        $result['processed'],
        $result['failed']
    );

    if ($cli) {
        fwrite(STDOUT, $line . PHP_EOL);
    } else {
        file_put_contents(Config::get('app.paths.logs') . '/queue.log', $line . PHP_EOL, FILE_APPEND);
    }

    if ($loop && $result['processed'] === 0 && $result['failed'] === 0) {
        sleep(3);
    }
} while ($loop);

if (!$cli) {
    Response::json(['ok' => true] + $result);
}
