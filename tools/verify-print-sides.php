<?php

declare(strict_types=1);

/**
 * A design printed on both sides gets both sides printed.
 *
 * Posts designs through the editor's own endpoint -- front only, back only and
 * both -- then runs the print step on each and checks which sheets come out and
 * that every sheet was drawn from its own side's file. Also checks the refusals
 * (a placement that disagrees with the design, a missing back file, an empty
 * design) and that a design from before migration 011 still reads as one file.
 *
 * Everything it creates it deletes again: its custom_designs rows (print_files
 * follow by cascade) and the files they point at. It places no order and never
 * writes to a row that existed before it ran.
 *
 *   php -S 127.0.0.1:8899 -t public
 *   php tools/verify-print-sides.php
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Repository\OrderRepository;
use App\Repository\PrintFileRepository;
use App\Service\PrintFileService;
use App\Support\Config;
use App\Support\Db;

$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8899';
$root = dirname(__DIR__);
$tmp  = $root . '/build/verify';

if (!is_dir($tmp)) {
    mkdir($tmp, 0775, true);
}

// Repeated runs would otherwise trip the upload rate limit, which is doing its
// job. Clear the counters so the suite measures the code, not itself.
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

/** @return array{status:int,body:string} */
function http(string $url, array $options = []): array
{
    static $jar = null;
    $jar ??= tempnam(sys_get_temp_dir(), 'jar');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
    ] + $options);

    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => $body];
}

/** A flat press file of one colour, at exactly the size the press expects. */
function pressFile(string $path, array $size, array $rgb): string
{
    $image = imagecreatetruecolor($size['w'], $size['h']);
    imagefill($image, 0, 0, imagecolorallocate($image, ...$rgb));
    imagepng($image, $path);

    return $path;
}

/** The colour at the middle of a PNG, as [r, g, b]. */
function centre(string $path): array
{
    $image = imagecreatefrompng($path);
    $rgb   = imagecolorat($image, intdiv(imagesx($image), 2), intdiv(imagesy($image), 2));

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

/* ----------------------------------------------------------- the session */

echo "\nEditor page and its config\n";

$page = http($base . '/design.php');
preg_match('~<script type="application/json" id="editor-config">(.*?)</script>~s', $page['body'], $m);
$config = isset($m[1]) ? json_decode(html_entity_decode($m[1]), true) : null;

check($page['status'] === 200 && is_array($config), 'design.php renders an editor config',
    'status ' . $page['status']);

if (!is_array($config)) {
    echo "\nNo garment type is editor-ready; set a base product on one in /admin/garments.php.\n";
    exit(1);
}

$endpoint = $base . $config['endpoint'];
$token    = (string) $config['csrf_token'];
$export   = $config['garment']['export_px'];

$red  = pressFile($tmp . '/side-front.png', $export, [220, 30, 30]);
$blue = pressFile($tmp . '/side-back.png', $export, [30, 60, 220]);

$text = static fn (string $value): array => ['type' => 'Textbox', 'text' => $value];

$state = static fn (array $front, array $back): string => json_encode([
    'version' => 1,
    'active'  => 'front',
    'sides'   => ['front' => ['objects' => $front], 'back' => ['objects' => $back]],
]);

/** @return array{status:int,json:?array} */
$post = static function (string $placement, string $designJson, array $files) use ($endpoint, $token): array {
    $fields = [
        '_token'      => $token,
        'action'      => 'save',
        'product_id'  => '0',
        'placement'   => $placement,
        'quantity'    => '1',
        'design_json' => $designJson,
    ];

    foreach ($files as $name => $path) {
        $fields[$name] = new CURLFile($path, 'image/png', basename($path));
    }

    $response = http($endpoint, [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $token, 'Accept: application/json'],
    ]);

    return ['status' => $response['status'], 'json' => json_decode($response['body'], true)];
};

$pdo     = Db::pdo();
$created = [];
$design  = static function (array $result) use ($pdo, &$created): ?array {
    $id = (int) ($result['json']['design_id'] ?? 0);

    if ($id === 0) {
        return null;
    }

    $created[] = $id;
    $stmt = $pdo->prepare('SELECT * FROM custom_designs WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
};

/* ------------------------------------------------------------ the saves */

echo "\nSaving through /api/design.php\n";

$frontOnly = $design($post('front', $state([$text('A')], []), ['print_png' => $red]));
check($frontOnly !== null && $frontOnly['placement'] === 'front' && $frontOnly['back_original_path'] === null,
    'front only: saved as front, one press file');

$backOnly = $design($post('back', $state([], [$text('B')]), ['print_png' => $blue]));
check($backOnly !== null && $backOnly['placement'] === 'back' && $backOnly['back_original_path'] === null,
    'back only: saved as back, one press file');

$both = $design($post('both', $state([$text('A')], [$text('B')]), ['print_png' => $red, 'print_png_back' => $blue]));
check($both !== null && $both['placement'] === 'both' && $both['back_original_path'] !== null,
    'both: saved as both, with a back press file');

if ($both !== null) {
    check(sha1_file($both['original_path']) === sha1_file($red), 'both: front press file is the front upload');
    check(sha1_file($both['back_original_path']) === sha1_file($blue), 'both: back press file is the back upload');
}

echo "\nRefusals\n";

$result = $post('front', $state([$text('A')], [$text('B')]), ['print_png' => $red]);
check($result['status'] === 422, 'both sides designed but placement front is refused (the old bug)',
    'status ' . $result['status']);
$design($result);

$result = $post('both', $state([$text('A')], [$text('B')]), ['print_png' => $red]);
check($result['status'] === 422 && isset($result['json']['errors']['print_png_back']),
    'both without a back press file is refused', 'status ' . $result['status']);
$design($result);

$result = $post('front', $state([], []), ['print_png' => $red]);
check($result['status'] === 422, 'an empty design is refused', 'status ' . $result['status']);
$design($result);

$result = $post('back', $state([$text('A')], []), ['print_png' => $red]);
check($result['status'] === 422, 'front designed but placement back is refused', 'status ' . $result['status']);
$design($result);

/* ------------------------------------------------------- the print step */

echo "\nPrint step\n";

$service = PrintFileService::make();
$files   = PrintFileRepository::make();
$printed = [];

$sheet = static function (array $row): array {
    return [
        'pdf'     => Config::get('app.paths.print') . '/' . $row['pdf_path'],
        'preview' => Config::get('app.paths.print') . '/' . $row['preview_path'],
    ];
};

foreach (['front only' => $frontOnly, 'back only' => $backOnly] as $label => $row) {
    if ($row === null) {
        continue;
    }

    $rows = $service->produce((int) $row['id']);
    $printed = array_merge($printed, $rows);
    $paths = $sheet($rows[0]);

    check(count($rows) === 1 && $rows[0]['side'] === 'single', $label . ': one single sheet');
    check(is_file($paths['pdf']) && is_file($paths['preview']), $label . ': PDF and preview written');
}

if ($both !== null) {
    $rows = $service->produce((int) $both['id']);
    $printed = array_merge($printed, $rows);

    check(array_column($rows, 'side') === ['front', 'back'], 'both: a front and a back sheet',
        implode(',', array_column($rows, 'side')));

    if (count($rows) === 2) {
        $front = $sheet($rows[0]);
        $back  = $sheet($rows[1]);

        check(is_file($front['pdf']) && is_file($back['pdf']), 'both: two PDFs written');
        check($front['pdf'] !== $back['pdf'], 'both: the two PDFs are different files');

        // The preview paints the artwork over the garment; its middle is the art.
        check(centre($front['preview']) === [220, 30, 30], 'both: front sheet drawn from the front file',
            implode(',', centre($front['preview'])));
        check(centre($back['preview']) === [30, 60, 220], 'both: back sheet drawn from the back file',
            implode(',', centre($back['preview'])));

        $again = $service->produce((int) $both['id']);
        check(array_column($again, 'id') === array_column($rows, 'id'), 'both: a retried job writes nothing new');

        // A job that died after the front: the retry does the back alone.
        $pdo->prepare('DELETE FROM print_files WHERE id = ?')->execute([(int) $rows[1]['id']]);
        $retry = $service->produce((int) $both['id']);
        $printed = array_merge($printed, $retry);
        check($retry[0]['id'] === $rows[0]['id'] && $retry[1]['side'] === 'back' && $retry[1]['id'] !== $rows[1]['id'],
            'both: a retry after a half-done job produces only the missing back');

        check((int) $files->forCustomDesign((int) $both['id'])['id'] === (int) $retry[0]['id'],
            'forCustomDesign() still returns the main (front) sheet');
        check($files->forCustomDesignSide((int) $both['id'], 'back') !== null, 'forCustomDesignSide(back) finds the back');
    }
}

/* ------------------------------------------- what existed before keeps */

echo "\nDesigns from before migration 011\n";

$legacy = $pdo->query(
    "SELECT custom_design_id FROM print_files WHERE side = 'single'
      AND custom_design_id NOT IN (" . implode(',', array_merge([0], $created)) . ') LIMIT 1'
)->fetchColumn();

if ($legacy !== false) {
    check(count($files->allForCustomDesign((int) $legacy)) === 1
        && $files->forCustomDesign((int) $legacy)['side'] === 'single',
        'an existing design still has its one single sheet', 'design #' . $legacy);
    check($service->produce((int) $legacy)[0]['side'] === 'single',
        'producing an existing design again writes nothing and returns its sheet');
} else {
    echo "  SKIP  no existing print file to compare against\n";
}

// The dashboard counter, on data with no two-sided design in an order yet, has
// to give what the old query gave.
$old = (int) $pdo->query(
    "SELECT COUNT(DISTINCT oi.custom_design_id) FROM order_items oi
       JOIN orders o ON o.id = oi.order_id
  LEFT JOIN print_files pf ON pf.custom_design_id = oi.custom_design_id
      WHERE oi.item_type = 'custom' AND oi.custom_design_id IS NOT NULL
        AND pf.id IS NULL AND o.status NOT IN ('cancelled', 'refunded')"
)->fetchColumn();
check(OrderRepository::make()->printFilesPending() === $old, 'print-files-pending count unchanged on existing orders',
    'old ' . $old . ' / new ' . OrderRepository::make()->printFilesPending());

/* --------------------------------------------------------------- tidy up */

foreach ($printed as $row) {
    foreach ($sheet($row) as $path) {
        @unlink($path);
    }

    foreach (glob(dirname($sheet($row)['pdf']) . '/design-' . $row['custom_design_id'] . '*') ?: [] as $leftover) {
        @unlink($leftover);
    }
}

foreach ($created as $id) {
    $stmt = $pdo->prepare('SELECT original_path, back_original_path, source_path FROM custom_designs WHERE id = ?');
    $stmt->execute([$id]);

    foreach (array_filter((array) $stmt->fetch()) as $path) {
        @unlink((string) $path);
    }

    // print_files rows go with it: ON DELETE CASCADE.
    $pdo->prepare('DELETE FROM custom_designs WHERE id = ?')->execute([$id]);
}

@unlink($red);
@unlink($blue);

echo "\n" . $pass . ' passed, ' . count($fail) . " failed\n";

foreach ($fail as $line) {
    echo '  - ' . $line . "\n";
}

exit($fail === [] ? 0 : 1);
