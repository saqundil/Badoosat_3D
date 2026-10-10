<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Print\PrintDriverFactory;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Db;
use App\Support\Format;
use App\Support\Lang;
use App\Support\UploadLimit;

Auth::require();

/*
 * What the shop can and cannot do on this server.
 *
 * Read-only, and written for the owner: every row says what the setting means
 * for the shop, not what the function is called. The page answers "is anything
 * wrong?" before it lists anything, because that is the question it is opened
 * with; the detail stays underneath for whoever is going to fix it.
 */

$drivers      = PrintDriverFactory::availability();
$selected     = PrintDriverFactory::make();
$uploadLimits = UploadLimit::sources();
$imagick      = extension_loaded('imagick');

$functions = [];

foreach (['proc_open', 'exec', 'shell_exec', 'popen'] as $function) {
    $disabled = in_array($function, array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    $functions[$function] = function_exists($function) && !$disabled;
}

try {
    $queue  = (int) (Db::pdo()->query('SELECT COUNT(*) AS pending FROM jobs')->fetch()['pending'] ?? 0);
    $failed = (int) (Db::pdo()->query('SELECT COUNT(*) AS failed FROM failed_jobs')->fetch()['failed'] ?? 0);
    $dbOk   = true;
} catch (PDOException) {
    $queue  = $failed = 0;
    $dbOk   = false;
}

/*
 * The three things that can be said about a check, in the order they matter:
 * something is broken, something is working but not at its best, or all is
 * well. The verdict at the top is the worst of them.
 */
$checks = [
    'database' => [
        'state' => $dbOk ? 'ok' : 'bad',
        'text'  => $dbOk ? Lang::get('admin.system.db_ok') : Lang::get('admin.system.db_bad'),
    ],
    'queue' => [
        'state' => $failed > 0 ? 'warn' : 'ok',
        'text'  => !$dbOk
            ? Lang::get('admin.system.queue_unknown')
            : Format::plural('admin.system.queue_pending', $queue)
                . Lang::get('admin.list_sep') . Format::plural('admin.system.queue_failed', $failed),
    ],
    'colour' => [
        'state' => $selected->name() === 'passthrough' ? 'warn' : 'ok',
        'text'  => $selected->name() === 'passthrough'
            ? Lang::get('admin.system.colour_rgb')
            : Lang::get('admin.system.colour_cmyk', ['driver' => $selected->name()]),
    ],
    'uploads' => [
        'state' => 'ok',
        'text'  => Lang::get('admin.system.upload_limit', ['mb' => UploadLimit::mebibytes(UploadLimit::bytes())]),
    ],
];

$worst = in_array('bad', array_column($checks, 'state'), true)
    ? 'bad'
    : (in_array('warn', array_column($checks, 'state'), true) ? 'warn' : 'ok');
$attention = count(array_filter($checks, static fn (array $c): bool => $c['state'] !== 'ok'));

$title   = Lang::get('admin.system.title');
$current = 'system';

$body = static function () use ($checks, $worst, $attention, $drivers, $selected, $functions, $uploadLimits, $imagick, $queue, $failed, $dbOk): void {
    $tone = ['ok' => 'active', 'warn' => 'stopped', 'bad' => 'stopped'];
    ?>
    <main class="dash dash--form">
        <?php admin_partial('page-header', [
            'title'    => Lang::get('admin.system.title'),
            'subtitle' => Lang::get('admin.system.subtitle'),
        ]); ?>

        <section class="verdict verdict--<?= e($worst) ?>" aria-labelledby="verdict-title">
            <span class="verdict__mark" aria-hidden="true"><?= admin_icon($worst === 'ok' ? 'check' : 'alert') ?></span>
            <div>
                <h2 class="verdict__title" id="verdict-title">
                    <?= e($worst === 'ok' ? Lang::get('admin.system.all_ok') : Format::plural('admin.system.needs_attention', $attention)) ?>
                </h2>
                <p class="verdict__text"><?= e(Lang::get('admin.system.verdict_' . $worst)) ?></p>
            </div>
        </section>

        <ul class="check-list">
            <?php foreach ($checks as $key => $check) { ?>
                <li class="check-row check-row--<?= e($check['state']) ?>">
                    <span class="check-row__mark" aria-hidden="true"><?= admin_icon($check['state'] === 'ok' ? 'check' : 'alert') ?></span>
                    <span class="check-row__text">
                        <span class="check-row__title"><?= e(Lang::get('admin.system.check.' . $key)) ?></span>
                        <span class="check-row__detail"><?= e($check['text']) ?></span>
                    </span>
                    <?php admin_partial('status-chip', [
                        'label' => Lang::get('admin.system.state.' . $check['state']),
                        'tone'  => $tone[$check['state']],
                    ]); ?>
                </li>
            <?php } ?>
        </ul>

        <?php admin_partial('settings', [
            'label' => Lang::get('admin.system.sections'),
            'sections' => [
                'sys-print' => [
                    'title' => Lang::get('admin.system.s.print'),
                    'help'  => Lang::get('admin.system.s.print_help'),
                    'body'  => static function () use ($drivers, $selected, $imagick): void {
                        admin_partial('data-table', [
                            'id'      => 'print-drivers',
                            'caption' => Lang::get('admin.system.s.print'),
                            'columns' => [
                                ['key' => 'name', 'label' => Lang::get('admin.system.col.driver'), 'primary' => true],
                                ['key' => 'here', 'label' => Lang::get('admin.system.col.available')],
                                ['key' => 'colour', 'label' => Lang::get('admin.system.col.colour')],
                            ],
                            'rows' => array_map(
                                static fn (string $name, bool $available): array => ['id' => $name, 'name' => $name, 'available' => $available],
                                array_keys($drivers),
                                array_values($drivers)
                            ),
                            'rowLabel' => static fn (array $r): string => (string) $r['name'],
                            'cell' => static function (array $row, string $key) use ($selected): void {
                                switch ($key) {
                                    case 'name': ?>
                                        <bdi class="code-text" translate="no"><?= e((string) $row['name']) ?></bdi>
                                        <?php if ($row['name'] === $selected->name()) {
                                            admin_partial('status-chip', ['label' => Lang::get('admin.system.in_use'), 'tone' => 'active']);
                                        }
                                        break;
                                    case 'here':
                                        echo e(Lang::get($row['available'] ? 'admin.system.yes' : 'admin.system.no'));
                                        break;
                                    default:
                                        echo e(Lang::get($row['name'] === 'passthrough' ? 'admin.system.colour_no' : 'admin.system.colour_yes'));
                                }
                            },
                        ]); ?>
                        <p class="form-field__help">
                            <?= e(Lang::get('admin.system.print_config', ['configured' => (string) Config::get('print.driver'), 'used' => $selected->name()])) ?>
                        </p>
                        <?php /* The extension is one way to reach CMYK, not the
                                 only one: the shell driver converts without it. */ ?>
                        <p class="form-field__help">
                            <?= e($imagick
                                ? Lang::get('admin.system.imagick_on')
                                : ($selected->name() === 'passthrough'
                                    ? Lang::get('admin.system.imagick_off')
                                    : Lang::get('admin.system.imagick_off_but', ['driver' => $selected->name()]))) ?>
                        </p>
                    <?php },
                ],

                'sys-uploads' => [
                    'title' => Lang::get('admin.system.s.uploads'),
                    'help'  => Lang::get('admin.system.s.uploads_help', ['mb' => UploadLimit::mebibytes(UploadLimit::bytes())]),
                    'body'  => static function () use ($uploadLimits): void {
                        admin_partial('data-table', [
                            'id'      => 'upload-limits',
                            'caption' => Lang::get('admin.system.s.uploads'),
                            'columns' => [
                                ['key' => 'source', 'label' => Lang::get('admin.system.col.source'), 'primary' => true],
                                ['key' => 'limit', 'label' => Lang::get('admin.system.col.limit'), 'align' => 'end'],
                            ],
                            'rows' => array_map(
                                static fn (string $source, int $bytes): array => ['id' => $source, 'source' => $source, 'bytes' => $bytes],
                                array_keys($uploadLimits),
                                array_values($uploadLimits)
                            ),
                            'rowLabel' => static fn (array $r): string => (string) $r['source'],
                            'cell' => static function (array $row, string $key): void {
                                if ($key === 'source') { ?>
                                    <bdi class="code-text" translate="no"><?= e((string) $row['source']) ?></bdi>
                                <?php } else {
                                    echo e(Lang::get('admin.system.megabytes', ['mb' => UploadLimit::mebibytes((int) $row['bytes'])]));
                                }
                            },
                        ]);
                    },
                ],

                'sys-queue' => [
                    'title' => Lang::get('admin.system.s.queue'),
                    'help'  => Lang::get('admin.system.s.queue_help'),
                    'body'  => static function () use ($queue, $failed, $dbOk): void { ?>
                        <?php if (!$dbOk) { ?>
                            <p class="form-field__error"><?= admin_icon('alert') ?><span><?= e(Lang::get('admin.system.db_bad')) ?></span></p>
                        <?php } else { ?>
                            <dl class="facts">
                                <div>
                                    <dt><?= e(Lang::get('admin.system.q_pending')) ?></dt>
                                    <dd><?= e(Format::number($queue)) ?></dd>
                                </div>
                                <div>
                                    <dt><?= e(Lang::get('admin.system.q_failed')) ?></dt>
                                    <dd class="<?= $failed > 0 ? 'facts__bad' : '' ?>"><?= e(Format::number($failed)) ?></dd>
                                </div>
                            </dl>
                        <?php } ?>
                        <p class="form-field__help"><?= e(Lang::get('admin.system.cron_help')) ?></p>
                    <?php },
                ],

                'sys-server' => [
                    'title' => Lang::get('admin.system.s.server'),
                    'help'  => Lang::get('admin.system.s.server_help'),
                    'body'  => static function () use ($functions): void {
                        admin_partial('data-table', [
                            'id'      => 'process-functions',
                            'caption' => Lang::get('admin.system.s.server'),
                            'columns' => [
                                ['key' => 'name', 'label' => Lang::get('admin.system.col.function'), 'primary' => true],
                                ['key' => 'state', 'label' => Lang::get('admin.system.col.available')],
                            ],
                            'rows' => array_map(
                                static fn (string $name, bool $available): array => ['id' => $name, 'name' => $name, 'available' => $available],
                                array_keys($functions),
                                array_values($functions)
                            ),
                            'rowLabel' => static fn (array $r): string => (string) $r['name'],
                            'cell' => static function (array $row, string $key): void {
                                if ($key === 'name') { ?>
                                    <bdi class="code-text" translate="no"><?= e((string) $row['name']) ?></bdi>
                                <?php } else {
                                    echo e(Lang::get($row['available'] ? 'admin.system.yes' : 'admin.system.blocked'));
                                }
                            },
                        ]);
                    },
                ],
            ],
        ]); ?>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
