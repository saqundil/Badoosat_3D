<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server, so a local measurement means something.
 *
 *   php -S 127.0.0.1:8899 -t public tools/router.php
 *
 * The built-in server does not read .htaccess, so it serves the editor island
 * as 1.27 MB of raw JavaScript where Apache sends 362 kB. Timing the editor
 * against that would be timing the test harness. All this does is apply the
 * compression public/.htaccess already declares for production; everything
 * else is handed straight back to the built-in server.
 *
 * It lives outside the document root on purpose — it is never reachable as a
 * URL, and nothing in production runs it.
 */

const COMPRESSIBLE = [
    'js'   => 'text/javascript',
    'mjs'  => 'text/javascript',
    'css'  => 'text/css',
    'json' => 'application/json',
    'svg'  => 'image/svg+xml',
];

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = realpath(__DIR__ . '/../public');
$file = realpath($root . $path);

// Only real files inside the document root, and only the types Apache would
// compress. Anything else — PHP, images, the model — falls through untouched.
if ($file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
    return false;
}

$type = COMPRESSIBLE[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;

if ($type === null || !str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip')) {
    return false;
}

$body = gzencode((string) file_get_contents($file), 6);

header('Content-Type: ' . $type . '; charset=utf-8');
header('Content-Encoding: gzip');
header('Content-Length: ' . strlen($body));
header('Vary: Accept-Encoding');
header('Cache-Control: no-cache');

echo $body;

return true;
