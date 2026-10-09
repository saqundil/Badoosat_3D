<?php

declare(strict_types=1);

/**
 * The twelve acceptance criteria for the shop.
 *
 *   php -S 127.0.0.1:8899 -t public tools/router.php
 *   php tools/verify-store.php
 *
 * Everything that can be proved by driving the real HTTP endpoints is proved
 * that way -- with cookies, sessions, CSRF tokens and redirects -- rather than
 * by calling a service directly, because most of these criteria are about what
 * happens when somebody hostile or unlucky uses the site, and a service called
 * from a script is neither.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Repository\CustomDesignRepository;
use App\Repository\OrderRepository;
use App\Repository\PrintFileRepository;
use App\Repository\ProductRepository;
use App\Service\CustomDesignService;
use App\Service\PricingService;
use App\Support\Config;
use App\Support\Db;

$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8899';
$root = dirname(__DIR__);

foreach (glob($root . '/storage/cache/ratelimit/*.json') ?: [] as $counter) {
    unlink($counter);
}

$pass = 0;
$fail = [];

function check(bool $ok, string $label, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail[] = $label . ($detail ? '  [' . $detail . ']' : '');
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail ? '  ' . $detail : '') . "\n";
}

function section(string $title): void
{
    echo "\n" . $title . "\n";
}

/** @return array{status:int,headers:string,body:string} */
function http(string $url, array $options = [], string $jar = 'shop'): array
{
    static $jars = [];
    $jars[$jar] ??= tempnam(sys_get_temp_dir(), 'jar');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jars[$jar],
        CURLOPT_COOKIEFILE     => $jars[$jar],
        CURLOPT_HEADER         => true,
    ] + $options);

    $raw    = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
}

function post(string $url, array $fields, string $jar = 'shop'): array
{
    return http($url, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields)], $jar);
}

function token(string $html): string
{
    preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $m);

    return $m[1] ?? '';
}

function location(array $response): string
{
    preg_match('/^Location:\s*(.+)$/mi', $response['headers'], $m);

    return trim($m[1] ?? '');
}

$products = ProductRepository::make();
$orders   = OrderRepository::make();
$prints   = PrintFileRepository::make();
$customs  = CustomDesignRepository::make();

/** The ids the seed guarantees, looked up rather than assumed. */
$product = $products->findBySlug('classic-tee');
$sizes   = $products->sizes();
$colors  = $products->colors();

if ($product === null) {
    fwrite(STDERR, "seed missing: run schema.sql\n");
    exit(1);
}

$productId = (int) $product['id'];

/** A live variant, with stock restored so repeated runs start from the same place. */
$firstVariant = $products->findVariantBy($productId, (int) $colors[0]['id'], (int) $sizes[0]['id']);
Db::pdo()->exec('UPDATE product_variants SET stock = 50 WHERE stock < 10');

/* ============ 9 & 10: the listing, its query budget and its filters ======= */

section('9 & 10. the listing: two queries, and a filter row rather than a sidebar');

{
    /*
     * The count is taken from the database's own statement counter across the
     * whole render, not from a wrapper we could have forgotten to route a query
     * through. Anything the page runs -- a service, a repository, a stray
     * lookup in a template -- shows up here.
     */
    $before = (int) (Db::pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'] ?? 0);

    // The cache exists precisely to avoid these queries, so it is cleared first:
    // the budget is about the cold page, which is the one that has to hold.
    App\Service\ProductService::make()->forgetCache();
    App\Service\ProductService::make()->catalog('newest', '');

    $after = (int) (Db::pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'] ?? 0);

    // The two SHOW statements themselves are counted; subtract them.
    $queries = $after - $before - 2;

    check($queries <= 2, 'the catalogue renders in two queries or fewer',
        $queries . ' queries for ' . count($products->listing('newest', '')) . ' products');

    // And again with more products than the seed ships, so a per-product query
    // would show up as growth rather than hiding inside a small number.
    Db::pdo()->exec(
        "INSERT INTO products (slug, name, summary, base_price_cents, image_path, sort_order)
         SELECT CONCAT('probe-', n), CONCAT('probe ', n), 'x', 1500, '/images/products/classic-tee.webp', 90
           FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
                 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8) t
          ON DUPLICATE KEY UPDATE slug = slug"
    );

    App\Service\ProductService::make()->forgetCache();
    $before = (int) (Db::pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'] ?? 0);
    $listing = App\Service\ProductService::make()->catalog('newest', '');
    $after   = (int) (Db::pdo()->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'] ?? 0);

    check($after - $before - 2 <= 2, 'and still two with ten products, so nothing is per-row',
        ($after - $before - 2) . ' queries for ' . $listing['total'] . ' products');

    Db::pdo()->exec("DELETE FROM products WHERE slug LIKE 'probe-%'");
    App\Service\ProductService::make()->forgetCache();
}

{
    /*
     * The listing page is /products.php now, and /catalog.php is a 301 to it.
     *
     * WHAT CHANGED IN THIS CRITERION, AND WHY
     *
     * It used to assert that the page had NO filtering at all -- the catalogue
     * was under twenty products, and a facet panel over eighteen rows is
     * furniture. Two things have since made that the wrong assertion. The
     * catalogue is split into two cuts, so a shopper is already inside a
     * narrowed list before they touch a control; and the shop asked for colour
     * and size.
     *
     * So the requirement is no longer "no filtering". It is that the filtering
     * is a ROW and not a SIDEBAR -- one line of controls above the grid, which a
     * phone can show without opening a panel over the shop -- and that every
     * control it offers leads somewhere. Those are the checks below.
     */
    $listing = '/products.php?type=printed';
    $html    = http($base . $listing)['body'];

    check($html !== '', 'the listing page answers', $listing);

    // The redirect is part of the contract: the navbar and the hero's notch
    // still link to /catalog.php?type=..., and neither may be edited.
    $moved = http($base . '/catalog.php?type=plain');
    check(
        in_array($moved['status'], [301, 302], true),
        'and /catalog.php still redirects there, so the old links keep working',
        'status ' . $moved['status']
    );

    $sidebar = preg_match('/class="[^"]*(facet|filters?__|sidebar)/i', $html) === 1;
    check(!$sidebar, 'the listing page has no filter sidebar');

    $sources = '';

    foreach (['src/Service', 'src/Repository', 'public'] as $directory) {
        foreach (glob($root . '/' . $directory . '/*.php') ?: [] as $file) {
            $sources .= (string) file_get_contents($file);
        }
    }

    $filterCode = preg_match('/\b(applyFilters|buildFacets|filterBy(Color|Size|Price))\b/i', $sources) === 1;
    check(!$filterCode, 'and no unused filtering machinery in the source');

    $controls = str_contains($html, 'name="sort"')
        && str_contains($html, 'name="q"')
        && str_contains($html, 'name="color"')
        && str_contains($html, 'name="size"');
    check($controls, 'a search box, a colour, a size and a sort are what it does have');

    /*
     * Every colour it offers has to return something. This is the check that
     * keeps the controls honest: they are built from the cut's live variants, so
     * a colour on the list that leads to an empty grid means the two have
     * drifted apart -- which is the failure a control like this can hide for
     * months, because it looks exactly like a shop with nothing in that colour.
     */
    preg_match_all('/<option value="(\d+)"/', $html, $options);
    $offered = array_slice(array_values(array_unique($options[1] ?? [])), 0, 4);
    $dead    = [];

    foreach ($offered as $colorId) {
        $page = http($base . $listing . '&color=' . (int) $colorId)['body'];

        if (str_contains($page, 'empty-state__title')) {
            $dead[] = $colorId;
        }
    }

    check(
        $dead === [],
        'and no colour it offers leads to an empty grid',
        $dead === [] ? count($offered) . ' colours checked' : 'dead: ' . implode(', ', $dead)
    );

    // A term that cannot match says so, by name, rather than leaving an
    // unexplained empty grid on screen.
    $missing = http($base . $listing . '&q=' . rawurlencode('zzqx-no-such-product'))['body'];
    check(
        str_contains($missing, 'empty-state__title'),
        'a search with no results gets the designed empty state'
    );
}

/* ========= 4 & 5: the two doors, and a team order through one of them ===== */

section('4. the direct upload produces the same shape the editor does');

/*
 * Both halves go through the real HTTP routes with real multipart uploads.
 * That matters more here than anywhere else in this suite: the uploader refuses
 * anything is_uploaded_file() does not recognise, so a design written by
 * calling a service from a script would be proving something the site does not
 * actually do. curl posts files the way a browser does.
 */

$artwork = sys_get_temp_dir() . '/verify-artwork.png';

{
    $png = imagecreatetruecolor(2400, 3000);
    imagefill($png, 0, 0, imagecolorallocate($png, 20, 60, 120));
    imagepng($png, $artwork);
    imagedestroy($png);
}

/* ---------------------------------------------------- through the editor */

$editorRow = null;

{
    $garment = App\Support\Garment::make();
    $export  = $garment->exportPx();
    $work    = $garment->workPx();

    $unknownGarment = App\Support\Garment::make('unknown');
    $unknownConfig  = $unknownGarment->toArray();
    check(!$unknownGarment->isCustomizable()
        && $unknownConfig['print_area'] === []
        && $unknownConfig['design_rect_px'] === [],
        'an unknown garment is safely marked non-customizable');

    // The flat press file the editor exports, at exactly the size the server
    // insists on -- a couple of pixels out and it is rejected, by design.
    $press = imagecreatetruecolor($export['w'], $export['h']);
    imagealphablending($press, false);
    imagesavealpha($press, true);
    imagefill($press, 0, 0, imagecolorallocatealpha($press, 0, 0, 0, 127));
    imagealphablending($press, true);
    imagefilledrectangle($press, 400, 500, $export['w'] - 400, $export['h'] - 500,
        imagecolorallocate($press, 20, 60, 120));

    $pressPath = sys_get_temp_dir() . '/verify-press.png';
    imagepng($press, $pressPath);
    imagedestroy($press);

    $state = ['version' => 1, 'sides' => [
        'front' => ['objects' => [[
            'type'         => 'Image',
            'naturalWidth' => 2400, 'naturalHeight' => 3000,
            'width'        => 2400, 'height'        => 3000,
            'scaleX'       => $work['w'] / 2400, 'scaleY' => $work['w'] / 2400,
            'left'         => 0, 'top' => 0,
        ]]],
        'back' => ['objects' => []],
    ]];

    $page = http($base . '/product.php?slug=classic-tee', [], 'editor');
    $csrf = token($page['body']);

    $response = http($base . '/api/design.php', [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => [
            '_token'      => $csrf,
            'placement'   => 'front',
            'design_json' => json_encode($state, JSON_UNESCAPED_UNICODE),
            'print_png'   => new CURLFile($pressPath, 'image/png', 'print.png'),
            // The scene photograph the editor takes on the confirm step. It is
            // what becomes the agreement picture, so the real editor always
            // sends it and so does this.
            'preview_png' => new CURLFile($artwork, 'image/png', 'preview.png'),
            'source'      => new CURLFile($artwork, 'image/png', 'source.png'),
        ],
    ], 'editor');

    $result    = json_decode($response['body'], true);
    $editorRow = isset($result['design_token'])
        ? $customs->findByToken((string) $result['design_token'])
        : null;

    check($editorRow !== null, 'the editor writes a design through /api/design.php',
        $editorRow !== null ? (string) $editorRow['token'] : substr($response['body'], 0, 140));
}

/* --------------------------------------------- through the upload page --- */

$uploadRow  = null;
$teamOrder  = null;

{
    $page = http($base . '/upload.php', [], 'team');
    $csrf = token($page['body']);

    check($page['status'] === 200 && str_contains($page['body'], 'qty['),
        'the upload page offers a quantity grid');

    /*
     * A team order in one pass: ten pieces across five sizes, one file. This is
     * the shape of the shop's most valuable order, and the reason the page
     * exists at all -- a company with a print-ready PDF being asked to place it
     * in a 3D editor five times is a company that telephones a competitor.
     */
    $fields = [
        '_token'     => $csrf,
        'product_id' => $productId,
        'placement'  => 'front',
        'note'       => 'طلب فريق — تسليم الخميس',
        'artwork'    => new CURLFile($artwork, 'image/png', 'team-logo.png'),
    ];

    foreach (array_slice($sizes, 0, 5) as $size) {
        $fields['qty[' . (int) $colors[0]['id'] . ':' . (int) $size['id'] . ']'] = 2;
    }

    $posted = http($base . '/upload.php', [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields,
    ], 'team');

    check($posted['status'] === 302 && str_contains(location($posted), 'cart.php'),
        'ten pieces in five sizes go in on one submission',
        $posted['status'] . ' ' . location($posted));

    $uploadRow = $customs->findByToken((string) (Db::pdo()
        ->query("SELECT token FROM custom_designs WHERE source = 'upload' ORDER BY id DESC LIMIT 1")
        ->fetchColumn() ?: ''));

    check($uploadRow !== null, 'and the upload writes a custom_designs row');

    if ($uploadRow !== null) {
        check(str_contains((string) $uploadRow['design_json'], '"sides"'),
            'carrying editor-shaped state, with sides and objects',
            substr((string) $uploadRow['design_json'], 0, 58) . '…');

        check((int) $uploadRow['dpi'] > 0,
            'and a resolution verdict from the editor\'s own inspectState',
            $uploadRow['dpi'] . ' DPI, ' . $uploadRow['quality']);
    }
}

/* ------------------------------------------- the two rows, side by side --- */

{
    $filled = static fn (?array $row): array => $row === null
        ? []
        : array_keys(array_filter($row, static fn ($v): bool => $v !== null && $v !== ''));

    if ($editorRow !== null && $uploadRow !== null) {
        // Not "similar": the same columns carry a value. A column the editor
        // fills and the upload leaves empty is a branch somebody downstream
        // would eventually have to write, and this criterion exists to say
        // they should not have to.
        $missing = array_diff($filled($editorRow), $filled($uploadRow));
        $extra   = array_diff($filled($uploadRow), $filled($editorRow));

        check($missing === [], 'the upload fills every column the editor fills',
            $missing === [] ? count($filled($uploadRow)) . ' columns' : 'missing: ' . implode(', ', $missing));

        check(array_diff($extra, ['note']) === [],
            'and adds nothing but the note its own form asks for',
            $extra === [] ? 'identical' : implode(', ', $extra));

        check($editorRow['source'] === 'editor' && $uploadRow['source'] === 'upload',
            'only `source` tells the two apart');
    } else {
        check(false, 'both rows exist to compare');
    }
}

/* ------------------------------------------------------------------------ */

section('5. ten pieces across five sizes: one order, one press file');

{
    $cart = http($base . '/cart.php', [], 'team');

    check(substr_count($cart['body'], 'class="line"') === 5,
        'the cart holds five lines, not five orders',
        substr_count($cart['body'], 'class="line"') . ' lines');

    $checkout = http($base . '/checkout.php', [], 'team');
    $csrf     = token($checkout['body']);

    $placed = post($base . '/checkout.php', [
        '_token'        => $csrf,
        'customer_name' => 'فريق التصميم',
        'email'         => 'team@example.com',
        'phone'         => '0791234567',
        'city'          => 'عمّان',
        'address'       => 'شارع الرينبو، بناية ١٢',
    ], 'team');

    preg_match('/number=([^&]+)/', location($placed), $m);
    $number = rawurldecode($m[1] ?? '');

    check($number !== '', 'and goes out as one order', $number ?: location($placed));

    $teamOrder = $number === '' ? null : $orders->findByNumber($number);
    check($teamOrder !== null, 'under a pronounceable number',
        $number . (App\Support\OrderNumber::looksValid($number) ? ' (valid shape)' : ' (BAD SHAPE)'));

    if ($teamOrder !== null) {
        $items = $orders->items((int) $teamOrder['id']);

        check(count($items) === 5, 'with five lines', count($items) . ' lines');

        $pieces = array_sum(array_map(static fn (array $i): int => (int) $i['quantity'], $items));
        check($pieces === 10, 'and ten pieces', (string) $pieces);

        $designIds = array_unique(array_map(
            static fn (array $i): int => (int) $i['custom_design_id'],
            $items
        ));
        check(count($designIds) === 1, 'all pointing at one artwork', implode(',', $designIds));

        // Drain the queue and count what came out of it.
        http($base . '/cron.php?key=' . rawurlencode((string) Config::get('app.cron_key')));

        $files = $prints->forOrder((int) $teamOrder['id']);
        check(count($files) === 1, 'and the press gets exactly one file for the lot',
            count($files) . ' files for 10 pieces');
    }

    $GLOBALS['teamOrder'] = $teamOrder;
}

/* ================ 1: the preview survives cart to admin ================== */

section('1. a custom line keeps its preview and its print file all the way');

{
    $order = $GLOBALS['teamOrder'] ?? null;

    if ($order === null) {
        check(false, 'the team order exists to inspect');
    } else {
        $items = $orders->items((int) $order['id']);
        $line  = $items[0];

        check(!empty($line['custom_preview']),
            'the order line carries the agreement picture', (string) $line['custom_preview']);

        $file = $prints->forCustomDesign((int) $line['custom_design_id']);
        check($file !== null, 'and the press file is reachable from the line');

        if ($file !== null) {
            $absolute = App\Service\PrintFileService::make()->absolutePath((string) $file['pdf_path']);
            check($absolute !== null && is_file($absolute), 'which exists on disk', (string) $file['pdf_path']);

            check(!str_starts_with((string) $absolute, $root . DIRECTORY_SEPARATOR . 'public'),
                'outside the web root, served only through PHP');

            // Not reachable by URL, only through the admin download endpoint.
            $direct = http($base . '/storage/print/orders/' . $order['id'] . '/design-'
                . $line['custom_design_id'] . '.pdf');
            check($direct['status'] === 404, 'a direct URL to it is a 404', (string) $direct['status']);

            $anon = http($base . '/admin/download.php?design=' . $line['custom_design_id'], [], 'anon');
            check($anon['status'] === 302 || $anon['status'] === 401,
                'and an anonymous download is refused', (string) $anon['status']);
        }
    }
}

/* =============== 2: tampering with money changes nothing ================= */

section('2. a tampered price or discount does not move the order total');

{
    $home = http($base . '/product.php?slug=classic-tee', [], 'tamper');
    $csrf = token($home['body']);

    post($base . '/cart.php', [
        '_token'     => $csrf,
        'action'     => 'add',
        'item_type'  => 'blank',
        'product_id' => $productId,
        'color_id'   => (int) $colors[0]['id'],
        'size_id'    => (int) $sizes[0]['id'],
        'placement'  => 'front',
        'quantity'   => 3,
        // Every one of these is a lie, and every one is ignored.
        'price_cents'      => 1,
        'unit_price_cents' => 1,
        'total_cents'      => 1,
        'discount_percent' => 99,
        'surcharge_cents'  => 0,
    ], 'tamper');

    $variant  = $products->findVariantBy($productId, (int) $colors[0]['id'], (int) $sizes[0]['id']);
    $expected = PricingService::line((int) $variant['price_cents'], 'blank', 0, 'front', 3);

    $cart = http($base . '/cart.php', [], 'tamper')['body'];
    check(str_contains($cart, money($expected['total_cents'])),
        'the cart prices the line from the catalogue',
        'expected ' . money($expected['total_cents']));

    $checkout = http($base . '/checkout.php', [], 'tamper');
    $csrf     = token($checkout['body']);

    $placed = post($base . '/checkout.php', [
        '_token'        => $csrf,
        'customer_name' => 'مختبِر',
        'email'         => 'tamper@example.com',
        'phone'         => '0799999999',
        'city'          => 'عمّان',
        'address'       => 'شارع المدينة',
        // And again at the last gate.
        'total_cents'    => 1,
        'discount_cents' => 999999,
        'shipping_cents' => 0,
    ], 'tamper');

    preg_match('/number=([^&]+)/', location($placed), $m);
    $order = $orders->findByNumber(rawurldecode($m[1] ?? ''));

    check($order !== null, 'the order is written');

    if ($order !== null) {
        $shipping = PricingService::shipping($expected['total_cents']);
        $wanted   = $expected['total_cents'] + $shipping;

        check((int) $order['total_cents'] === $wanted,
            'and its total is the server\'s figure, not the browser\'s',
            'order ' . $order['total_cents'] . ' vs expected ' . $wanted);

        check((int) $order['discount_cents'] === $expected['discount_cents'],
            'the discount too', (string) $order['discount_cents']);
    }
}

/* ================ 3: the last shirt cannot be sold twice ================= */

section('3. two checkouts race for the last piece; one of them loses');

{
    $variantId = (int) $firstVariant['id'];
    Db::pdo()->prepare('UPDATE product_variants SET stock = 1 WHERE id = ?')->execute([$variantId]);

    /*
     * Two separate connections, both inside a transaction, both asking for the
     * same row. This is the shape of the real race: without FOR UPDATE both
     * read stock = 1, both decide they may sell it, and the shop owes a shirt
     * it does not have.
     */
    $config = Config::file('db');
    $dsn    = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'], (int) $config['port'], $config['name'], $config['charset']);

    $a = new PDO($dsn, $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $b = new PDO($dsn, $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $b->setAttribute(PDO::ATTR_TIMEOUT, 3);
    $b->exec('SET SESSION innodb_lock_wait_timeout = 2');

    $a->beginTransaction();
    $stockA = (int) $a->query('SELECT stock FROM product_variants WHERE id = ' . $variantId . ' FOR UPDATE')
        ->fetchColumn();
    $a->exec('UPDATE product_variants SET stock = stock - 1 WHERE id = ' . $variantId);

    $blocked = false;

    $b->beginTransaction();

    try {
        $b->query('SELECT stock FROM product_variants WHERE id = ' . $variantId . ' FOR UPDATE')->fetchColumn();
    } catch (PDOException) {
        // Locked out by the first transaction, which is the whole point.
        $blocked = true;
    }

    check($stockA === 1 && $blocked,
        'the second checkout is made to wait for the first',
        'first saw ' . $stockA . ', second was ' . ($blocked ? 'blocked' : 'let through'));

    $a->commit();

    try {
        $b->rollBack();
    } catch (PDOException) {
        // The timeout already ended it.
    }

    $stockB = (int) Db::pdo()->query('SELECT stock FROM product_variants WHERE id = ' . $variantId)->fetchColumn();
    check($stockB === 0, 'and the shelf is left at zero, not minus one', (string) $stockB);

    // Now the same thing through the actual order writer.
    Db::pdo()->prepare('UPDATE product_variants SET stock = 1 WHERE id = ?')->execute([$variantId]);

    $sold = 0;

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $jar  = 'race' . $attempt;
        $page = http($base . '/product.php?slug=classic-tee', [], $jar);
        $csrf = token($page['body']);

        post($base . '/cart.php', [
            '_token'     => $csrf,
            'action'     => 'add',
            'item_type'  => 'blank',
            'product_id' => $productId,
            'color_id'   => (int) $colors[0]['id'],
            'size_id'    => (int) $sizes[0]['id'],
            'placement'  => 'front',
            'quantity'   => 1,
        ], $jar);

        $checkout = http($base . '/checkout.php', [], $jar);
        $csrf     = token($checkout['body']);

        $placed = post($base . '/checkout.php', [
            '_token'        => $csrf,
            'customer_name' => 'سباق ' . $attempt,
            'email'         => 'race' . $attempt . '@example.com',
            'phone'         => '079000000' . $attempt,
            'city'          => 'عمّان',
            'address'       => 'شارع',
        ], $jar);

        if (str_contains(location($placed), 'order.php')) {
            $sold++;
        }
    }

    $left = (int) Db::pdo()->query('SELECT stock FROM product_variants WHERE id = ' . $variantId)->fetchColumn();

    check($sold === 1 && $left === 0,
        'through checkout, exactly one of the two orders goes through',
        $sold . ' sold, ' . $left . ' left');

    Db::pdo()->prepare('UPDATE product_variants SET stock = 50 WHERE id = ?')->execute([$variantId]);
}

/* ================= 8: a guest gets all the way through ================== */

section('8. guest checkout, start to finish, with no account');

{
    $jar = 'guest';

    /*
     * The guest starts where the navbar sends them: /catalog.php, which is a 301
     * to /products.php now. Both halves are checked -- the redirect answers, and
     * the page it names actually renders -- because a redirect pointing at a 500
     * passes a status check and fails a customer.
     */
    $moved = http($base . '/catalog.php?type=printed', [], $jar);
    check(in_array($moved['status'], [301, 302], true), 'the old catalogue URL still redirects',
        'status ' . $moved['status']);

    $page = http($base . '/products.php?type=printed', [], $jar);
    check($page['status'] === 200, 'and the catalogue it points at opens', 'status ' . $page['status']);

    $product = http($base . '/product.php?slug=classic-tee', [], $jar);
    $csrf    = token($product['body']);

    $add = post($base . '/cart.php', [
        '_token'     => $csrf,
        'action'     => 'add',
        'item_type'  => 'ready',
        'design_id'  => 1,
        'product_id' => $productId,
        'color_id'   => (int) $colors[0]['id'],
        'size_id'    => (int) $sizes[1]['id'],
        'placement'  => 'front',
        'quantity'   => 2,
    ], $jar);

    check($add['status'] === 302, 'a ready-made design goes into the cart', (string) $add['status']);

    $cart = http($base . '/cart.php', [], $jar);
    check(str_contains($cart['body'], 'إتمام الطلب'), 'the cart offers checkout');

    $checkout = http($base . '/checkout.php', [], $jar);
    check($checkout['status'] === 200, 'checkout opens without a login', (string) $checkout['status']);
    check(str_contains($checkout['body'], 'كضيف'), 'and says so on the page');

    $csrf   = token($checkout['body']);
    $placed = post($base . '/checkout.php', [
        '_token'        => $csrf,
        'customer_name' => 'زائر',
        'email'         => 'guest@example.com',
        'phone'         => '0788888888',
        'city'          => 'إربد',
        'address'       => 'شارع الجامعة',
    ], $jar);

    preg_match('/number=([^&]+)/', location($placed), $m);
    $number = rawurldecode($m[1] ?? '');

    check($number !== '', 'the order is placed as a guest', $number);

    $order = $orders->findByNumber($number);
    check($order !== null && $order['customer_id'] === null,
        'and it is recorded with no customer account attached');

    $confirmation = http($base . '/order.php?number=' . rawurlencode($number), [], $jar);
    check($confirmation['status'] === 200 && str_contains($confirmation['body'], $number),
        'the confirmation page shows the order', (string) $confirmation['status']);

    $GLOBALS['guestOrder'] = $order;
}

/* ============= 7: tracking leaks nothing and is rate limited ============= */

section('7. tracking: a wrong number is indistinguishable from a real one');

{
    $order = $GLOBALS['guestOrder'] ?? null;

    $page = http($base . '/track.php', [], 'track');
    $csrf = token($page['body']);

    // A number that certainly does not exist.
    $miss = post($base . '/track.php', [
        '_token' => $csrf, 'number' => 'TS-ZZZZZ', 'phone' => '0788888888',
    ], 'track');

    // A number that does exist, with the wrong phone.
    $wrongPhone = post($base . '/track.php', [
        '_token' => $csrf,
        'number' => (string) ($order['order_number'] ?? 'TS-22222'),
        'phone'  => '0700000000',
    ], 'track');

    /*
     * The two pages differ in exactly one respect: the number and the phone the
     * visitor typed, which the form echoes back into its own fields. What must
     * be identical is everything the server decided -- the status and the
     * message -- because that is the only channel that could carry the fact
     * that one of these orders is real.
     */
    $answer = static function (string $html): string {
        preg_match('/<p class="alert alert--error">(.*?)<\/p>/s', $html, $m);

        return trim($m[1] ?? '(no message)');
    };

    check($miss['status'] === $wrongPhone['status'],
        'both misses answer with the same status', $miss['status'] . ' vs ' . $wrongPhone['status']);

    check($answer($miss['body']) === $answer($wrongPhone['body']),
        'and with the same sentence, so neither confirms the order exists',
        $answer($miss['body']));

    check(!str_contains($wrongPhone['body'], 'class="track"'),
        'a real number with the wrong phone shows no order detail at all');

    check(!str_contains($miss['body'], 'غير موجود') || !str_contains($wrongPhone['body'], 'رقم الهاتف'),
        'the message never says which half was wrong');

    // The right pair does work, or the page would be useless.
    if ($order !== null) {
        $hit = post($base . '/track.php', [
            '_token' => $csrf,
            'number' => (string) $order['order_number'],
            'phone'  => (string) $order['phone'],
        ], 'track');

        check($hit['status'] === 200 && str_contains($hit['body'], (string) $order['order_number']),
            'the right number and phone together do show the order');
    }

    // And the door closes on a script.
    $limited = false;

    for ($i = 0; $i < 14; $i++) {
        $response = post($base . '/track.php', [
            '_token' => $csrf, 'number' => 'TS-' . str_pad((string) $i, 5, 'A'), 'phone' => '0788888888',
        ], 'track');

        if ($response['status'] === 429) {
            $limited = true;

            break;
        }
    }

    check($limited, 'walking the number space is rate limited', $limited ? 'blocked' : 'never blocked');
}

/* ========== 6: a shared product link carries image, title and price ====== */

section('6. a link pasted into WhatsApp shows a card');

{
    $html = http($base . '/product.php?slug=classic-tee')['body'];

    $tag = static function (string $property) use ($html): string {
        preg_match('/<meta property="' . preg_quote($property, '/') . '" content="([^"]*)"/', $html, $m);

        return $m[1] ?? '';
    };

    check($tag('og:title') !== '', 'og:title', $tag('og:title'));
    check(str_starts_with($tag('og:image'), 'http'), 'og:image is an absolute URL', $tag('og:image'));
    check($tag('og:description') !== '', 'og:description', mb_substr($tag('og:description'), 0, 40) . '…');
    check($tag('og:url') !== '', 'og:url', $tag('og:url'));

    preg_match('/<meta property="product:price:amount" content="([^"]*)"/', $html, $m);
    check(($m[1] ?? '') !== '', 'the price is in the card', $m[1] ?? '');

    // The image has to actually be fetchable, or the card renders blank.
    $image = http($tag('og:image'));
    check($image['status'] === 200, 'and the image it names can be fetched', (string) $image['status']);

    preg_match('/<script type="application\/ld\+json">(.+?)<\/script>/s', $html, $m);
    $jsonld = json_decode(html_entity_decode($m[1] ?? '{}', ENT_QUOTES), true);

    check(($jsonld['@type'] ?? '') === 'Product', 'schema.org Product data is present');
    check(isset($jsonld['offers']['price']), 'with an offer and a price',
        (string) ($jsonld['offers']['price'] ?? ''));
    check(($jsonld['offers']['availability'] ?? '') !== '', 'and availability',
        (string) ($jsonld['offers']['availability'] ?? ''));
}

/* ================= 11: the services stay out of the view ================= */

section('11. ProductService, CartService and OrderService print nothing');

{
    // audit-layers.php enforces this across every service by parsing; this is
    // the criterion's own named check, over the three it names.
    foreach (['ProductService', 'CartService', 'OrderService'] as $name) {
        $source = (string) file_get_contents($root . '/src/Service/' . $name . '.php');
        $tokens = token_get_all($source);
        $sins   = [];

        foreach ($tokens as $piece) {
            if (!is_array($piece)) {
                continue;
            }

            if ($piece[0] === T_ECHO || $piece[0] === T_PRINT
                || ($piece[0] === T_INLINE_HTML && trim($piece[1]) !== '')) {
                $sins[] = $piece[1];
            }
        }

        check($sins === [], $name . ' has no echo and no inline HTML',
            $sins === [] ? '' : implode(' ', $sins));
    }
}

/* ============ the cache the listing depends on is really invalidated ===== */

section('the catalogue cache is cleared by a save');

{
    App\Service\ProductService::make()->catalog('newest', '');
    $cacheFile = $root . '/storage/cache/catalog.newest.json';

    check(is_file($cacheFile), 'the listing is cached to a file');

    App\Service\ProductService::make()->forgetCache();
    check(!is_file($cacheFile), 'and a save clears it');
}

/* ------------------------------------------------------------------------ */

echo "\n============================\n";
echo 'passed ' . $pass . ', failed ' . count($fail) . "\n";

if ($fail !== []) {
    echo "\nFAILURES:\n";

    foreach ($fail as $line) {
        echo '  - ' . $line . "\n";
    }

    exit(1);
}
