<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\HomeSettingsService;
use App\Service\ProductService;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Response;
use App\Support\Uploader;
use App\Support\UploadException;

Auth::require();

/*
 * The method check is BEFORE the token check, which is the order the other save
 * endpoints use -- and the order that makes both checks mean something. The other
 * way round, a GET carries no token, so Csrf::require() answers first and a
 * mistyped URL in the address bar produces "the form has expired" instead of
 * "this endpoint takes a POST". The method check was dead code until this was
 * fixed.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('POST only.', 405);
}

Csrf::require($_POST);

/*
 * The write side of /admin/home.php. Same shape as admin/save.php and
 * admin/product-save.php: a POST target that validates, writes, and redirects --
 * it renders nothing, so a refresh after saving cannot repost the form.
 *
 * SECURITY, IN ONE PLACE
 *
 * Auth::require(), the method check and Csrf::require() are the first three
 * statements and run before anything is read out of the request. Every database write below goes through a
 * prepared statement. Every upload goes through Support\Uploader, which is the
 * class the rest of the shop already uses: it ignores the declared extension and
 * the browser-supplied MIME type, takes the real type from finfo, generates a
 * random filename, and refuses anything that is not an image it recognises. There
 * is no second implementation of that check here, on purpose -- a copy is a copy
 * that will not get the next fix.
 */

$service = HomeSettingsService::make();
$action  = (string) ($_POST['action'] ?? '');

/** Puts the reason in the session and goes back; see admin/home.php on why. */
$fail = static function (string $message): never {
    $_SESSION['home_error'] = $message;
    Response::redirect('/admin/home.php');
};

if ($action === 'trending') {
    /*
     * Everything is cleared first, then the ticked rows are set.
     *
     * A form that only writes what it was sent cannot express "this one is no
     * longer trending" -- an unticked checkbox sends nothing at all, so the row
     * would keep its old flag forever and unticking would appear not to work. One
     * UPDATE to zero the column, then one per selected row, in a transaction so a
     * failure halfway cannot leave the home page empty.
     */
    $ids = array_values(array_unique(array_map('intval', (array) ($_POST['trending'] ?? []))));
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));

    /*
     * Eight is the limit the home page asks for, and it is enforced here as well
     * as there. Enforcing it only in the query would let an owner tick twenty,
     * see eight, and have no idea which eight -- so the extra ticks are refused
     * with the reason, rather than silently dropped by a LIMIT.
     */
    if (count($ids) > 8) {
        $fail('اخترت ' . count($ids) . ' منتجًا، والحد الأقصى ثمانية. أزل بعض الاختيارات ثم احفظ.');
    }

    $orders = (array) ($_POST['order'] ?? []);
    $pdo    = Db::pdo();

    $pdo->beginTransaction();

    try {
        $pdo->exec('UPDATE products SET is_trending = 0, trending_order = 0 WHERE is_trending = 1');

        if ($ids !== []) {
            $stmt = $pdo->prepare(
                'UPDATE products SET is_trending = 1, trending_order = ? WHERE id = ?'
            );

            foreach ($ids as $id) {
                // Clamped to the column's range rather than trusted: the input has
                // min and max attributes, and neither of those is a constraint.
                $order = max(0, min(999, (int) ($orders[$id] ?? 0)));
                $stmt->execute([$order, $id]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Trending save failed — ' . $e->getMessage());
        $fail('تعذّر حفظ المنتجات الرائجة. حاول مرة أخرى.');
    }

    // The strip is cached, so a save has to say so; nothing else will.
    ProductService::make()->forgetCache();

    Response::redirect('/admin/home.php?trending=1');
}

if ($action !== 'settings') {
    $fail('طلب غير معروف.');
}

/* ------------------------------------------------------------------ the text */

/*
 * Trimmed, and an empty string is kept as an empty string rather than rejected: an
 * owner clearing the title is asking for the default back, and
 * HomeSettingsService::settings() is what supplies it. The maxlength on the inputs
 * is a courtesy; these are the limits.
 */
$values = [
    'promo_title'  => mb_substr(trim((string) ($_POST['promo_title'] ?? '')), 0, 120),
    'promo_text'   => mb_substr(trim((string) ($_POST['promo_text'] ?? '')), 0, 400),
    'promo_button' => mb_substr(trim((string) ($_POST['promo_button'] ?? '')), 0, 40),
];

/* ---------------------------------------------------------------- the images */

$stored   = $service->stored();
$uploader = Uploader::make();
$cleared  = array_map('strval', (array) ($_POST['clear'] ?? []));

/*
 * The paths of files this request replaced, deleted only after everything else has
 * succeeded.
 *
 * Deleting the old file at the moment the new one is stored would be a bug with a
 * long fuse: if a later field in the same submission failed, the row would still
 * point at a path whose file had already gone, and the section would render a
 * broken image with no way back. Collected here, unlinked at the end.
 */
$superseded = [];

foreach (HomeSettingsService::IMAGE_KEYS as $key) {
    $file    = $_FILES[$key] ?? null;
    $hasFile = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    // Asked for the current image to go, and did not choose a new one.
    if (!$hasFile && in_array($key, $cleared, true)) {
        $values[$key] = '';

        if ($stored[$key] !== '') {
            $superseded[] = $stored[$key];
        }

        continue;
    }

    /*
     * No file and no clear box: the field is left exactly as it was. This is why
     * the loop does not simply write $_POST -- a file input sends nothing when the
     * owner edits only the banner's wording, and a save that treated that as
     * "remove the image" would wipe all three pictures on every text edit.
     */
    if (!$hasFile) {
        continue;
    }

    try {
        /*
         * bucket 'home', so these land in public/uploads/home/ beside the hero's
         * and the products' own folders. The name is 32 random hex characters and
         * the extension comes from the detected type, never from what was sent.
         */
        $result = $uploader->store($file, 'home');
    } catch (UploadException $e) {
        // The class's messages are already written for the shop owner in Arabic.
        $fail($e->getMessage());
    }

    /*
     * SVG is accepted by the Uploader -- artwork needs it -- and is refused here.
     * These three values are printed into a background position on the landing
     * page, where a vector that scales to any size is the wrong tool, and where
     * the sanitiser's guarantees are a bigger surface than this screen needs. The
     * file is removed rather than left orphaned in uploads/home/.
     */
    if ($result['mime'] === 'image/svg+xml') {
        $path = (string) Config::get('app.paths.public') . $result['path'];

        if (is_file($path)) {
            unlink($path);
        }

        $fail('صور الواجهة تكون JPEG أو PNG أو WebP. صيغة SVG غير مناسبة هنا.');
    }

    $values[$key] = $result['path'];

    if ($stored[$key] !== '' && $stored[$key] !== $result['path']) {
        $superseded[] = $stored[$key];
    }
}

$service->save($values);

/* ------------------------------------------------- the files that were replaced */

/*
 * Now that the row points at the new file, the old one can go.
 *
 * Confined to /uploads/home/ by an explicit check rather than by trusting the
 * value: these come out of the database, and an unlink() driven by a database
 * value is worth one line of paranoia. realpath() resolves any '..' before the
 * comparison, so a stored path cannot walk out of the directory it names.
 */
$uploads = rtrim((string) Config::get('app.paths.uploads'), '/\\') . DIRECTORY_SEPARATOR . 'home';
$root    = realpath($uploads);

foreach (array_unique($superseded) as $path) {
    if ($root === false || !str_starts_with($path, '/uploads/home/')) {
        continue;
    }

    $absolute = realpath((string) Config::get('app.paths.public') . $path);

    if ($absolute !== false && str_starts_with($absolute, $root) && is_file($absolute)) {
        unlink($absolute);
    }
}

Response::redirect('/admin/home.php?saved=1');
