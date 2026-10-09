<?php

declare(strict_types=1);

/*
 * Puts the hero into a known state so the acceptance run has something
 * definite to measure, and puts it back afterwards.
 *
 *   php tools/hero-fixture.php snapshot      remember every slide as it is now
 *   php tools/hero-fixture.php model         one active slide, with a model
 *   php tools/hero-fixture.php no-model      one active slide, with none
 *   php tools/hero-fixture.php nudge DX DY   drive the badges into a corner
 *   php tools/hero-fixture.php parallax 0|1  set the pointer-motion switch
 *   php tools/hero-fixture.php garment HEX  paint every shirt this colour
 *   php tools/hero-fixture.php restore       put the remembered state back
 *
 * The "one active slide" part is the point: the promise being checked is that
 * a slide with no model fetches no 3D, and with two slides on the card the
 * other one would answer for it.
 */

use App\Service\HeroSlideService;
use App\Support\Cache;
use App\Support\Db;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

$snapshot = dirname(__DIR__) . '/storage/hero-fixture.json';
$command  = $argv[1] ?? '';
$pdo      = Db::pdo();

$forget = static function (): void {
    Cache::store()->forget(HeroSlideService::CACHE_KEY);
};

switch ($command) {
    case 'snapshot':
        /*
         * Refuses to overwrite one that is already there.
         *
         * A snapshot taken while a previous run's fixture is still in
         * force records the fixture as though it were the real state, and
         * restore then faithfully puts the wrong thing back -- which is
         * how a probe print ended up saved on a live slide.
         */
        if (is_file($snapshot)) {
            fwrite(STDERR, "A snapshot is already held; restore before taking another.
");
            exit(1);
        }

        $rows = $pdo->query(
            'SELECT id, has_model, is_active, layout, parallax_enabled, color_id, garment_color,
                    design_id, placement, show_badges, design_image_path
             FROM hero_slides'
        )->fetchAll();
        file_put_contents($snapshot, json_encode($rows, JSON_THROW_ON_ERROR));
        echo 'snapshot: ' . count($rows) . " slide(s)\n";

        break;

    case 'model':
    case 'no-model':
        $first = $pdo->query(
            'SELECT id FROM hero_slides ORDER BY sort_order ASC, id ASC LIMIT 1'
        )->fetchColumn();

        if ($first === false) {
            fwrite(STDERR, "No slides to work with.\n");
            exit(1);
        }

        $pdo->exec('UPDATE hero_slides SET is_active = 0');
        $stmt = $pdo->prepare('UPDATE hero_slides SET is_active = 1, has_model = ? WHERE id = ?');
        $stmt->execute([$command === 'model' ? 1 : 0, $first]);
        $forget();
        echo 'slide ' . $first . ': has_model = ' . ($command === 'model' ? '1' : '0') . "\n";

        break;

    case 'nudge':
        /*
         * The worst a nudge can be, written straight into the column: the
         * editor's own limit, aimed at the corner the call to action is cut
         * into. Every slide gets it, so whichever one is on screen is hostile.
         */
        $dx = (float) ($argv[2] ?? -45);
        $dy = (float) ($argv[3] ?? 45);
        $pdo->prepare('UPDATE hero_slides SET layout = ?')->execute([json_encode(
            ['stats' => ['dx' => $dx, 'dy' => $dy], 'tagline' => ['dx' => $dx, 'dy' => $dy]],
            JSON_THROW_ON_ERROR
        )]);
        $forget();
        echo 'every slide nudged by ' . $dx . ',' . $dy . PHP_EOL;

        break;

    case 'parallax':
        $on = ($argv[2] ?? '1') === '1' ? 1 : 0;
        $pdo->prepare('UPDATE hero_slides SET parallax_enabled = ?')->execute([$on]);
        $forget();
        echo 'parallax_enabled = ' . $on . ' on every slide' . PHP_EOL;

        break;

    case 'garment':
        /*
         * Any hex, not just a palette row: the point is to prove the print
         * survives whatever is under it, and 'yellow' is not in the shop's
         * six colours. color_id is cleared so the hydrator falls back to
         * the hex column, which is the path a slide with an unmatched
         * colour already takes.
         */
        $hex = strtoupper((string) ($argv[2] ?? '#FFFFFF'));

        if (!preg_match('/^#[0-9A-F]{6}$/', $hex)) {
            fwrite(STDERR, "Expected #RRGGBB.
");
            exit(1);
        }

        $pdo->prepare('UPDATE hero_slides SET color_id = NULL, garment_color = ?')->execute([$hex]);
        $forget();
        echo 'every shirt ' . $hex . PHP_EOL;

        break;

    case 'testprint':
        /*
         * A print whose colours are unmistakable, for the check that the
         * garment's colour never reaches them: four saturated blocks on a
         * transparent ground, drawn here rather than committed, so the test
         * does not depend on what the catalogue happens to hold. The seeded
         * artwork is near-black ink, which proves nothing on a black shirt.
         */
        $file = dirname(__DIR__) . '/public/uploads/hero/print-probe.png';
        $size = 1200;
        $im = imagecreatetruecolor($size, $size);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        $blocks = [[255, 40, 40], [40, 220, 90], [60, 120, 255], [250, 240, 60]];

        foreach ($blocks as $i => [$r, $g, $b]) {
            imagefilledrectangle(
                $im,
                ($i % 2) * ($size / 2) + 40, intdiv($i, 2) * ($size / 2) + 40,
                ($i % 2) * ($size / 2) + ($size / 2) - 40,
                intdiv($i, 2) * ($size / 2) + ($size / 2) - 40,
                imagecolorallocatealpha($im, $r, $g, $b, 0)
            );
        }

        imagepng($im, $file);
        imagedestroy($im);

        $first = $pdo->query(
            'SELECT id FROM hero_slides ORDER BY sort_order ASC, id ASC LIMIT 1'
        )->fetchColumn();
        $pdo->exec('UPDATE hero_slides SET is_active = 0');
        $pdo->prepare(
            "UPDATE hero_slides
                SET is_active = 1, has_model = 1, show_badges = 0, parallax_enabled = 0,
                    layout = NULL, design_id = NULL, placement = 'front',
                    design_image_path = ?, color_id = ?
              WHERE id = ?"
        )->execute(['/uploads/hero/print-probe.png', (int) ($argv[2] ?? 0), $first]);
        $forget();
        echo 'slide ' . $first . ': probe print on colour ' . (int) ($argv[2] ?? 0) . PHP_EOL;

        break;

    case 'colors':
        /* The palette, so a check does not have to reach into the schema. */
        echo json_encode(
            App\Repository\ProductRepository::make()->colors(),
            JSON_UNESCAPED_UNICODE
        ), PHP_EOL;

        break;

    case 'paint':
        /*
         * One slide, one model, one design, one colour -- the fixture the
         * print-colour check needs, so the same artwork can be photographed
         * on one shirt after another with nothing else moving.
         */
        $first = $pdo->query(
            'SELECT id FROM hero_slides ORDER BY sort_order ASC, id ASC LIMIT 1'
        )->fetchColumn();
        $pdo->exec('UPDATE hero_slides SET is_active = 0');
        $pdo->prepare(
            "UPDATE hero_slides
                SET is_active = 1, has_model = 1, show_badges = 0, parallax_enabled = 0,
                    layout = NULL, design_image_path = NULL, placement = 'front',
                    color_id = ?, design_id = ?
              WHERE id = ?"
        )->execute([(int) ($argv[2] ?? 0), ((int) ($argv[3] ?? 0)) ?: null, $first]);
        $forget();
        echo 'slide ' . $first . ': colour ' . (int) ($argv[2] ?? 0)
            . ', design ' . (int) ($argv[3] ?? 0) . PHP_EOL;

        break;

    case 'restore':
        if (!is_file($snapshot)) {
            fwrite(STDERR, "Nothing to restore.\n");
            exit(1);
        }

        $stmt = $pdo->prepare(
            'UPDATE hero_slides
                SET has_model = ?, is_active = ?, layout = ?, parallax_enabled = ?,
                    color_id = ?, garment_color = ?, design_id = ?, placement = ?,
                    show_badges = ?, design_image_path = ?
              WHERE id = ?'
        );

        foreach (json_decode((string) file_get_contents($snapshot), true, 8, JSON_THROW_ON_ERROR) as $row) {
            $stmt->execute([
                (int) $row['has_model'],
                (int) $row['is_active'],
                $row['layout'],
                (int) ($row['parallax_enabled'] ?? 1),
                $row['color_id'] === null ? null : (int) $row['color_id'],
                $row['garment_color'],
                $row['design_id'] === null ? null : (int) $row['design_id'],
                (string) $row['placement'],
                (int) $row['show_badges'],
                $row['design_image_path'],
                (int) $row['id'],
            ]);
        }

        unlink($snapshot);
        $forget();
        echo "restored\n";

        break;

    default:
        fwrite(STDERR, "Usage: hero-fixture.php snapshot|model|no-model|nudge|parallax|garment|restore\n");
        exit(1);
}
