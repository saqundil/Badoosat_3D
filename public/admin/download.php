<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Repository\PrintFileRepository;
use App\Service\PrintFileService;
use App\Support\Auth;
use App\Support\Response;

Auth::require();

/*
 * Print output lives in storage/print, outside the web root. Nothing there is
 * reachable by URL: it is read here, after the session check, and the stored
 * path is resolved with realpath so a crafted value cannot climb out of the
 * folder it is confined to.
 *
 * The file is addressed by the design it belongs to, not by a path -- there is
 * no filename in the query string for anyone to bend.
 */

/* A design printed differently front and back has one sheet per side and is
   asked for with &side=; without it, a design's only (or front) sheet. */
$designId = (int) ($_GET['design'] ?? 0);
$side     = (string) ($_GET['side'] ?? '');
$file     = in_array($side, ['front', 'back'], true)
    ? PrintFileRepository::make()->forCustomDesignSide($designId, $side)
    : PrintFileRepository::make()->forCustomDesign($designId);

if ($file === null) {
    Response::error('لا يوجد ملف طباعة لهذا التصميم.', 404);
}

$type = ($_GET['type'] ?? 'pdf') === 'preview' ? 'preview' : 'pdf';
$path = PrintFileService::make()->absolutePath((string) $file[$type . '_path']);

if ($path === null || !is_file($path)) {
    Response::error('الملف غير موجود على القرص.', 404);
}

header('Content-Type: ' . ($type === 'pdf' ? 'application/pdf' : 'image/png'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
