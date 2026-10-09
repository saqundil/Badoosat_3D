<?php

declare(strict_types=1);

/**
 * End to end: upload a design, put it in the cart, place the order, drain the
 * queue, and check the press file that comes out the other side.
 *
 *   php -S 127.0.0.1:8899 -t public
 *   php tools/verify-orders.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Repository\OrderRepository;
use App\Service\PricingService;
use App\Repository\PrintFileRepository;
use App\Support\Config;
use App\Support\Db;

$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8899';
$root = dirname(__DIR__);
$tmp  = $root . '/build/verify';

if (!is_dir($tmp)) {
    mkdir($tmp, 0775, true);
}

// Repeated runs would otherwise trip the login rate limit, which is doing its
// job. Clear the rate-limit counters so the suite measures the code, not itself.
foreach (glob($root . '/storage/cache/ratelimit/*.json') ?: [] as $counter) {
    unlink($counter);
}

/*
 * The catalogue's own ids rather than literals. Colours and sizes are rows now,
 * and a test that hard-codes '#1C1C1E' and 'L' is a test that breaks when the
 * shop renames a colour -- which is exactly the coupling the reference tables
 * were introduced to remove.
 */
$products     = App\Repository\ProductRepository::make();
$productRow   = $products->findBySlug('classic-tee');
$productId    = (int) ($productRow['id'] ?? 0);
$colorRows    = $products->colors();
$sizeRows     = $products->sizes();
$byHex        = array_column($colorRows, 'id', 'hex');
$byCode       = array_column($sizeRows, 'id', 'code');
$blackId      = (int) ($byHex['#1C1C1E'] ?? $colorRows[0]['id']);
$ivoryId      = (int) ($byHex['#EFEDE7'] ?? $colorRows[0]['id']);
$largeId      = (int) ($byCode['L'] ?? $sizeRows[0]['id']);
$mediumId     = (int) ($byCode['M'] ?? $sizeRows[0]['id']);
$variantPrice = (int) ($products->findVariantBy($productId, $blackId, $largeId)['price_cents'] ?? 0);

$pass = 0;
$fail = [];

function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail[] = $label . ($detail ? '  [' . $detail . ']' : '');
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail ? '  ' . $detail : '') . "\n";
}

function http(string $url, array $options = [], string $jarName = 'shop'): array
{
    static $jars = [];
    $jars[$jarName] ??= tempnam(sys_get_temp_dir(), 'jar');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jars[$jarName],
        CURLOPT_COOKIEFILE     => $jars[$jarName],
        CURLOPT_HEADER         => true,
    ] + $options);

    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
}

function token(string $html): string
{
    preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $m);

    return $m[1] ?? '';
}

/* ------------------------------------------------------- a low-res design */

echo "\n--- the shopper's path ---\n";

$artwork = $tmp . '/customer-art.png';
// 1500x1850 lands at about 134 DPI over 28x35 cm: printable, but worth warning
// about. Below 100 DPI the service refuses the file outright.
$image = imagecreatetruecolor(1500, 1850);
imagefilledrectangle($image, 0, 0, 1500, 1850, imagecolorallocate($image, 18, 18, 22));
imagefilledellipse($image, 750, 870, 1030, 1030, imagecolorallocate($image, 233, 196, 0));
imagefilledrectangle($image, 640, 870, 860, 1680, imagecolorallocate($image, 233, 196, 0));
imagepng($image, $artwork);
imagedestroy($image);

$product = http($base . '/product.php?slug=classic-tee');
$shopToken = token($product['body']);
check($shopToken !== '', 'the product page issues a CSRF token');

$upload = http($base . '/api/upload.php', [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => [
        '_token'    => $shopToken,
        'placement' => 'front',
        'design'    => new CURLFile($artwork, 'image/png', 'customer-art.png'),
    ],
]);
$design = json_decode($upload['body'], true);

check($upload['status'] === 200 && isset($design['token']), 'the design uploads', $upload['body']);
check(($design['check']['quality'] ?? '') === 'warn',
    'a 1500x1850 file warns before the sale rather than after it',
    ($design['check']['dpi'] ?? '?') . ' DPI — ' . ($design['check']['message'] ?? ''));

$add = http($base . '/cart.php', [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_token'       => $shopToken,
        'action'       => 'add',
        'item_type'    => 'custom',
        'product_id'   => $productId,
        'color_id'     => $blackId,
        'size_id'      => $largeId,
        'placement'    => 'front',
        'custom_token' => $design['token'],
        'quantity'     => 2,
    ]),
]);
// A rejected line redirects back to the product page, so the destination is
// what proves the line was actually accepted.
check($add['status'] === 302 && str_contains($add['headers'], '/cart.php'),
    'the line goes into the cart',
    'HTTP ' . $add['status'] . ' -> ' . (preg_match('/^Location:\s*(.*)$/mi', $add['headers'], $l) ? trim($l[1]) : '?'));

$cartJson = json_decode(http($base . '/api/cart.php')['body'], true);
check(($cartJson['cart']['pieces'] ?? 0) === 2, 'the JSON cart shows the same two pieces',
    ($cartJson['cart']['pieces'] ?? 0) . ' pieces');

// Two garments plus the custom-print surcharge on each, all read back from the
// catalogue and config/shop.php. Nothing in the request above named a price.
$expectedTwo = PricingService::line($variantPrice, 'custom', 0, 'front', 2)['total_cents'];
check(($cartJson['cart']['subtotal_cents'] ?? 0) === $expectedTwo,
    'priced from the catalogue, not from the client',
    money($cartJson['cart']['subtotal_cents'] ?? 0) . ' vs ' . money($expectedTwo));

// A tampered price must be ignored: the cart carries no money at all.
$tamper = http($base . '/cart.php', [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_token' => $shopToken, 'action' => 'add', 'item_type' => 'blank',
        'product_id' => $productId, 'color_id' => $ivoryId, 'size_id' => $mediumId,
        'placement' => 'front',
        'quantity' => 1, 'unit_price_cents' => 1, 'price' => 1,
    ]),
]);
$after    = json_decode(http($base . '/api/cart.php')['body'], true);
$expected = $expectedTwo + PricingService::line($variantPrice, 'blank', 0, 'front', 1)['total_cents'];
check(($after['cart']['subtotal_cents'] ?? 0) === $expected, 'a posted price is ignored',
    money($after['cart']['subtotal_cents'] ?? 0) . ' vs ' . money($expected));

$checkoutPage = http($base . '/checkout.php');
$checkoutToken = token($checkoutPage['body']);

$order = http($base . '/checkout.php', [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => http_build_query([
        '_token'        => $checkoutToken,
        'customer_name' => 'سامي قنديل',
        'email'         => 'sami@example.com',
        'phone'         => '0790000000',
        'city'          => 'عمّان',
        'address'       => 'عمّان، الأردن',
        'note'          => 'اتصل قبل التسليم',
    ]),
]);
preg_match('/number=([^&\s]+)/', $order['headers'], $m);
$reference = rawurldecode($m[1] ?? '');

check($order['status'] === 302 && $reference !== '', 'the order is placed', $reference);

$orders = OrderRepository::make();
$placed = $orders->findByNumber($reference);
check($placed !== null, 'the order is in the database');

$wanted = $expected + PricingService::shipping($expected);
check($placed !== null && (int) $placed['total_cents'] === $wanted, 'the stored total matches the cart',
    money((int) ($placed['total_cents'] ?? 0)) . ' vs ' . money($wanted));

$items = $placed === null ? [] : $orders->items((int) $placed['id']);
check(count($items) === 2, 'both lines were written', count($items) . ' lines');

/* ----------------------------------------------------------- the queue --- */

echo "\n--- the queue ---\n";

$pending = (int) Db::pdo()->query('SELECT COUNT(*) c FROM jobs')->fetch()['c'];
check($pending >= 1, 'a print job was queued for the printed line', $pending . ' pending');

$cronKey = (string) Config::get('app.cron_key', 'dev-cron-key');
$cron = http($base . '/cron.php?key=' . urlencode($cronKey));
$cronResult = json_decode($cron['body'], true);

check($cron['status'] === 200, 'cron.php runs with the right key', 'HTTP ' . $cron['status']);
check(($cronResult['processed'] ?? 0) >= 1, 'it processed the job',
    json_encode($cronResult));
check(($cronResult['failed'] ?? 1) === 0, 'nothing failed');

$noKey = http($base . '/cron.php');
check($noKey['status'] === 403, 'cron.php refuses a request with no key', 'HTTP ' . $noKey['status']);

$left = (int) Db::pdo()->query('SELECT COUNT(*) c FROM jobs')->fetch()['c'];
check($left === 0, 'the queue is empty afterwards', $left . ' left');

/* ------------------------------------------------------- the press file -- */

echo "\n--- the press file ---\n";

/*
 * A press file belongs to the artwork, not to the order line -- several lines
 * of one design share one sheet -- so it is looked up by the design the line
 * points at.
 */
$printed    = null;
$printedFor = 0;

foreach ($items as $item) {
    if ($item['custom_design_id'] !== null) {
        $printedFor = (int) $item['id'];
        $printed    = PrintFileRepository::make()->forCustomDesign((int) $item['custom_design_id']);

        break;
    }
}

check($printed !== null, 'a print_files row was written');

if ($printed !== null) {
    $printRoot = Config::get('app.paths.print');
    $pdf = $printRoot . '/' . $printed['pdf_path'];
    $preview = $printRoot . '/' . $printed['preview_path'];

    check(is_file($pdf), 'the PDF exists', $printed['pdf_path']);
    check(is_file($preview), 'the agreement preview exists', $printed['preview_path']);
    check(str_starts_with((string) file_get_contents($pdf, false, null, 0, 5), '%PDF-'), 'it really is a PDF');

    $size = getimagesize($preview);
    check($size !== false && $size[0] === 800, 'the preview is 800 px wide',
        $size === false ? 'unreadable' : $size[0] . 'x' . $size[1]);

    check(!str_starts_with(realpath($pdf), (string) realpath(Config::get('app.paths.public'))),
        'print output lives outside the web root', dirname($printed['pdf_path']));

    check(in_array($printed['colour_space'], ['CMYK', 'RGB'], true)
        && $printed['driver'] !== '',
        'the row records which driver made it and in what colour space',
        $printed['driver'] . ' / ' . $printed['colour_space']);

    // Reachable only through PHP, and only when signed in.
    $designId = (int) $printed['custom_design_id'];
    $anon = http($base . '/admin/download.php?design=' . $designId . '&type=pdf', [], 'anon');
    check($anon['status'] === 302 || $anon['status'] === 401,
        'an anonymous download is refused', 'HTTP ' . $anon['status']);

    $login = http($base . '/admin/login.php', [], 'staff');
    http($base . '/admin/login.php', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => http_build_query([
            '_token'   => token($login['body']),
            'username' => getenv('ADMIN_USER') ?: 'admin',
            'password' => getenv('ADMIN_PASSWORD') ?: 'hero-admin-2026',
        ]),
    ], 'staff');

    $staff = http($base . '/admin/download.php?design=' . $designId . '&type=pdf', [], 'staff');
    check($staff['status'] === 200 && str_starts_with($staff['body'], '%PDF-'),
        'a signed-in administrator gets the file', 'HTTP ' . $staff['status']);

    $traversal = http($base . '/storage/print/' . $printed['pdf_path'], [], 'staff');
    check($traversal['status'] === 404, 'the storage folder is not reachable by URL',
        'HTTP ' . $traversal['status']);
}

echo "\n============================\n";
echo 'passed ' . $pass . ', failed ' . count($fail) . "\n";

if ($fail !== []) {
    echo "\nFAILURES:\n";

    foreach ($fail as $line) {
        echo '  - ' . $line . "\n";
    }

    exit(1);
}
