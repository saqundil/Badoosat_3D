<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Repository\OrderRepository;
use App\Service\GarmentService;
use App\Service\HeroSlideService;
use App\Service\OrderService;
use App\Support\Auth;
use App\Support\Db;
use App\Support\Format;
use App\Support\Lang;

Auth::require();

/*
 * The dashboard's front page (template A): what is waiting, what is selling,
 * and what needs a decision.
 *
 * Every figure here is also somewhere else -- the point of the page is that
 * they are in one place, and that each one is a link to the screen where it
 * can be acted on. Nothing is written from here.
 */

/** A variant at or under this many units counts as running low. */
const LOW_STOCK = 4;

$orders = OrderRepository::make();

$stats = null;
$queue = [];
$recent = [];
$listFailed = false;

try {
    $now        = new DateTimeImmutable('now');
    $monthStart = $now->modify('first day of this month')->setTime(0, 0);
    $lastStart  = $monthStart->modify('-1 month');
    $month      = $orders->totalsBetween($monthStart->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'));

    // This month so far against the same stretch of last month, as Orders does.
    $lastSoFar = min($lastStart->add($monthStart->diff($now)), $monthStart);
    $last      = $orders->totalsBetween($lastStart->format('Y-m-d H:i:s'), $lastSoFar->format('Y-m-d H:i:s'));

    $first = $now->setTime(0, 0)->modify('-13 days');
    $daily = $orders->dailyCounts($first->format('Y-m-d H:i:s'));
    $bars  = [];

    for ($i = 0; $i < 14; $i++) {
        $d     = $first->modify('+' . $i . ' days');
        $n     = $daily[$d->format('Y-m-d')] ?? 0;
        $bars[] = [
            'label'   => Format::date($d, 'day'),
            'value'   => $n,
            'text'    => Format::plural('admin.count.orders', $n),
            'current' => $i === 13,
        ];
    }

    $counts = $orders->countByStatus();
    $stats  = [
        'counts'       => $counts,
        'month'        => $month,
        'last'         => $last,
        'bars'         => $bars,
        'oldest'       => $orders->oldestWaiting(),
        'printPending' => $orders->printFilesPending(),
    ];

    $recent = $orders->recent(6);
    $queue  = $orders->recent(6, 'in_production');
} catch (Throwable $e) {
    error_log('admin/overview: ' . $e->getMessage());
    $listFailed = true;
}

/* ------------------------------------------------- what needs a decision */

$alerts = [];

try {
    $pdo = Db::pdo();

    $low = (int) $pdo->query(
        'SELECT COUNT(*) FROM product_variants v
           JOIN products p ON p.id = v.product_id
          WHERE v.is_active = 1 AND p.is_active = 1 AND v.stock > 0 AND v.stock <= ' . LOW_STOCK
    )->fetchColumn();

    $out = (int) $pdo->query(
        'SELECT COUNT(*) FROM product_variants v
           JOIN products p ON p.id = v.product_id
          WHERE v.is_active = 1 AND p.is_active = 1 AND v.stock = 0'
    )->fetchColumn();

    /* A product on sale with nothing to sell: active, but no active variant. */
    $unsellable = (int) $pdo->query(
        'SELECT COUNT(*) FROM products p
          WHERE p.is_active = 1
            AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1)'
    )->fetchColumn();

    if ($out > 0) {
        $alerts[] = ['kind' => 'bad', 'text' => Format::plural('admin.overview.alert.out', $out),
                     'href' => '/admin/catalog.php', 'action' => Lang::get('admin.overview.go_stock')];
    }
    if ($low > 0) {
        $alerts[] = ['kind' => 'warn', 'text' => Format::plural('admin.overview.alert.low', $low),
                     'href' => '/admin/catalog.php', 'action' => Lang::get('admin.overview.go_stock')];
    }
    if ($unsellable > 0) {
        $alerts[] = ['kind' => 'warn', 'text' => Format::plural('admin.overview.alert.unsellable', $unsellable),
                     'href' => '/admin/products.php', 'action' => Lang::get('admin.overview.go_products')];
    }
    if (($stats['printPending'] ?? 0) > 0) {
        $alerts[] = ['kind' => 'warn', 'text' => Format::plural('admin.stat.print_files_pending', (int) $stats['printPending']),
                     'href' => '/admin/orders.php?tab=printing', 'action' => Lang::get('admin.overview.go_orders')];
    }

    /* A cut the customer editor cannot offer, and a hero with nothing live. */
    $blocked = count(array_filter(GarmentService::make()->all(), static fn (array $t): bool => $t['editor_problem'] !== null));
    if ($blocked > 0) {
        $alerts[] = ['kind' => 'warn', 'text' => Format::plural('admin.overview.alert.garments', $blocked),
                     'href' => '/admin/garments.php', 'action' => Lang::get('admin.overview.go_garments')];
    }

    $liveSlides = count(array_filter(HeroSlideService::make()->allSlides(), static fn (array $s): bool => (bool) $s['is_active']));
    if ($liveSlides === 0) {
        $alerts[] = ['kind' => 'bad', 'text' => Lang::get('admin.overview.alert.no_slides'),
                     'href' => '/admin/', 'action' => Lang::get('admin.overview.go_slides')];
    }
} catch (Throwable $e) {
    error_log('admin/overview alerts: ' . $e->getMessage());
}

/** "+12.5% on the same days last month", or why there is nothing to compare with. */
$trend = static function (int $now, int $before): array {
    if ($before === 0) {
        return ['direction' => 'flat', 'text' => Lang::get('admin.trend.no_base')];
    }

    $change = ($now - $before) / $before;

    return [
        'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
        'text'      => Lang::get('admin.trend.vs_same_days', ['pct' => Format::percent(abs($change))]),
    ];
};

$title   = Lang::get('admin.overview.title');
$current = 'overview';

$body = static function () use ($stats, $trend, $recent, $queue, $alerts, $listFailed): void {
    ?>
    <main class="dash">
        <?php admin_partial('page-header', [
            'title'    => Lang::get('admin.overview.title'),
            'subtitle' => Lang::get('admin.overview.subtitle'),
            'actions'  => static function (): void { ?>
                <a class="button button--primary" href="/admin/orders.php">
                    <?= admin_icon('inbox') ?><?= e(Lang::get('admin.overview.go_orders')) ?>
                </a>
            <?php },
        ]); ?>

        <section class="stat-grid" aria-label="<?= e(Lang::get('admin.overview.summary')) ?>">
            <?php if ($stats === null) {
                foreach (['inbox', 'printer', 'stack', 'trend'] as $icon) {
                    admin_partial('stat-card', ['label' => Lang::get('admin.state.error_title'), 'icon' => $icon, 'figure' => null]);
                }
            } else {
                $c       = $stats['counts'];
                $waiting = ($c['pending'] ?? 0) + ($c['paid'] ?? 0);
                $value   = Format::moneyParts($stats['month']['value_cents']);

                admin_partial('stat-card', [
                    'label'  => Lang::get('admin.stat.waiting'),
                    'icon'   => 'inbox',
                    'figure' => Format::number($waiting),
                    'note'   => $stats['oldest'] === null
                        ? Lang::get('admin.stat.waiting_none')
                        : Lang::get('admin.stat.oldest', ['age' => Format::ago($stats['oldest'])]),
                ]);
                admin_partial('stat-card', [
                    'label'  => Lang::get('admin.stat.printing'),
                    'icon'   => 'printer',
                    'figure' => Format::number($c['in_production'] ?? 0),
                    'note'   => Format::plural('admin.stat.print_files_pending', $stats['printPending']),
                ]);
                admin_partial('stat-card', [
                    'label'     => Lang::get('admin.stat.orders_month'),
                    'icon'      => 'stack',
                    'figure'    => Format::number($stats['month']['orders']),
                    'trend'     => $trend($stats['month']['orders'], $stats['last']['orders']),
                    'bars'      => $stats['bars'],
                    'barsLabel' => Lang::get('admin.stat.daily_label'),
                ]);
                admin_partial('stat-card', [
                    'label'      => Lang::get('admin.stat.value_month'),
                    'icon'       => 'trend',
                    'figure'     => $value['figure'],
                    'unit'       => $value['symbol'],
                    'unitBefore' => $value['before'],
                    'trend'      => $trend($stats['month']['value_cents'], $stats['last']['value_cents']),
                ]);
            } ?>
        </section>

        <?php if ($alerts !== []) { ?>
            <section class="alert-list" aria-labelledby="overview-alerts">
                <h2 class="panel-block__title" id="overview-alerts"><?= e(Lang::get('admin.overview.attention')) ?></h2>
                <ul class="check-list">
                    <?php foreach ($alerts as $alert) { ?>
                        <li class="check-row check-row--<?= e($alert['kind']) ?>">
                            <span class="check-row__mark" aria-hidden="true"><?= admin_icon('alert') ?></span>
                            <span class="check-row__text"><span class="check-row__title"><?= e($alert['text']) ?></span></span>
                            <a class="button button--secondary button--small" href="<?= e($alert['href']) ?>"><?= e($alert['action']) ?></a>
                        </li>
                    <?php } ?>
                </ul>
            </section>
        <?php } ?>

        <div class="overview-split">
            <?php
            /* Two lists, one shape: the newest orders, and what is on the press. */
            $list = static function (string $id, string $titleKey, array $rows, string $href, string $emptyKey) use ($listFailed): void { ?>
                <section class="panel-card" aria-labelledby="<?= e($id) ?>-title">
                    <header class="panel-card__head">
                        <h2 class="panel-card__title" id="<?= e($id) ?>-title"><?= e(Lang::get($titleKey)) ?></h2>
                        <a class="button button--ghost button--small" href="<?= e($href) ?>"><?= e(Lang::get('admin.overview.see_all')) ?></a>
                    </header>

                    <?php if ($listFailed) {
                        admin_partial('state', ['kind' => 'error', 'text' => Lang::get('admin.state.error_list'), 'hint' => Lang::get('admin.state.error_hint')]);
                    } elseif ($rows === []) {
                        admin_partial('state', ['kind' => 'empty', 'text' => Lang::get($emptyKey)]);
                    } else { ?>
                        <ul class="mini-list">
                            <?php foreach ($rows as $order) { ?>
                                <li>
                                    <a class="mini-row" href="/admin/orders.php?id=<?= (int) $order['id'] ?>">
                                        <span class="avatar avatar--light" aria-hidden="true"><bdi><?= e(admin_initials((string) $order['customer_name'])) ?></bdi></span>
                                        <span class="mini-row__text">
                                            <span class="mini-row__id"><bdi>#<?= e((string) $order['order_number']) ?></bdi></span>
                                            <span class="mini-row__meta">
                                                <bdi><?= e((string) $order['customer_name']) ?></bdi>
                                                <span aria-hidden="true">·</span>
                                                <time datetime="<?= e(Format::iso((string) $order['created_at'])) ?>"><?= e(Format::ago((string) $order['created_at'])) ?></time>
                                            </span>
                                        </span>
                                        <?php admin_partial('status-chip', ['status' => (string) $order['status']]); ?>
                                        <span class="mini-row__total"><?php admin_partial('money', ['cents' => (int) $order['total_cents']]); ?></span>
                                    </a>
                                </li>
                            <?php } ?>
                        </ul>
                    <?php } ?>
                </section>
            <?php };

            $list('latest-orders', 'admin.overview.recent', $recent, '/admin/orders.php', 'admin.orders.empty');
            $list('on-the-press', 'admin.overview.queue', $queue, '/admin/orders.php?tab=printing', 'admin.overview.queue_empty');
            ?>
        </div>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
