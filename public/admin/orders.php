<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Repository\OrderRepository;
use App\Repository\PrintFileRepository;
use App\Service\OrderService;
use App\Service\ValidationException;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Format;
use App\Support\Lang;
use App\Support\Mail;
use App\Support\Response;

Auth::require();

$service = OrderService::make();
$orders  = OrderRepository::make();
$notice  = null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::require($_POST);

    try {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $status  = (string) ($_POST['status'] ?? '');

        $service->changeStatus($orderId, $status);

        // The customer hears about despatch and about nothing else. An email
        // for every internal state change trains people to ignore the emails.
        if ($status === 'shipped') {
            Mail::orderShipped($orderId);
        }

        $_SESSION['notice'] = Lang::get('admin.orders.updated');
        Response::redirect('/admin/orders.php?id=' . $orderId);
    } catch (ValidationException $e) {
        $error = implode(' ', $e->errors());
    }
}

$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);

/*
 * The view's state is all in the URL -- tab, date range, search, selected
 * order -- so every screen is a link, and a reload or a shared URL lands on the
 * same thing. Anything malformed is dropped rather than rejected.
 */
$tab = (string) ($_GET['tab'] ?? 'all');
$tab = array_key_exists($tab, OrderService::TABS) ? $tab : 'all';

$day = static function (string $name): ?string {
    $value = (string) ($_GET[$name] ?? '');
    $date  = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
};
$from  = $day('from');
$to    = $day('to');
$query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);

$explicit = isset($_GET['id']);

/* ------------------------------------------------------- the list + detail */

$list     = [];
$counts   = [];
$selected = null;
$listFailed = false;

try {
    $counts = $orders->countByStatus();
    $list   = $orders->search(OrderService::TABS[$tab], $from, $to, $query);

    $selectedId = $explicit ? (int) $_GET['id'] : (int) ($list[0]['id'] ?? 0);
    $selected   = $selectedId > 0 ? $service->find($selectedId) : null;

    if ($selected !== null) {
        // Keyed on the artwork, because that is what a press file belongs to: ten
        // lines sharing one design share one file and must show one download.
        // Then on the side: a design printed differently front and back has a
        // sheet for each, and the line offers both.
        $selected['prints'] = [];

        foreach (PrintFileRepository::make()->forOrder((int) $selected['id']) as $file) {
            $selected['prints'][(int) $file['custom_design_id']][(string) $file['side']] = $file;
        }
    }
} catch (Throwable $e) {
    error_log('admin/orders list: ' . $e->getMessage());
    $listFailed = true;
}

/* --------------------------------------------------------------- the cards */

$stats = null;

try {
    $now        = new DateTimeImmutable('now');
    $monthStart = $now->modify('first day of this month')->setTime(0, 0);
    $month      = $orders->totalsBetween($monthStart->format('Y-m-d H:i:s'), $monthStart->modify('+1 month')->format('Y-m-d H:i:s'));
    $last       = $orders->totalsBetween($monthStart->modify('-1 month')->format('Y-m-d H:i:s'), $monthStart->format('Y-m-d H:i:s'));

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

    $stats = [
        'counts'       => $counts ?: $orders->countByStatus(),
        'month'        => $month,
        'last'         => $last,
        'bars'         => $bars,
        'oldest'       => $orders->oldestWaiting(),
        'printPending' => $orders->printFilesPending(),
    ];
} catch (Throwable $e) {
    error_log('admin/orders stats: ' . $e->getMessage());
}

/** "+12.5% from last month", or why there is nothing to compare with. */
$trend = static function (int $now, int $before): array {
    if ($before === 0) {
        return ['direction' => 'flat', 'text' => Lang::get('admin.trend.no_base')];
    }

    $change = ($now - $before) / $before;

    return [
        'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
        'text'      => Lang::get('admin.trend.vs_last_month', ['pct' => Format::percent(abs($change))]),
    ];
};

$title   = Lang::get('admin.orders.title');
$current = 'orders';

$body = static function () use (
    $list, $counts, $selected, $explicit, $listFailed, $stats, $trend,
    $tab, $from, $to, $query, $notice, $error,
): void {
    $sum = static fn (array $statuses): int => $statuses === []
        ? array_sum($counts)
        : array_sum(array_intersect_key($counts, array_flip($statuses)));

    $tabs = [];

    foreach (OrderService::TABS as $key => $statuses) {
        $tabs[] = [
            'key'     => $key,
            'label'   => Lang::get('admin.orders.tab.' . $key),
            'count'   => Format::number($sum($statuses)),
            'href'    => admin_url(['tab' => $key === 'all' ? null : $key, 'id' => null]),
            'current' => $key === $tab,
        ];
    }
    ?>
    <main class="dash">
        <?php admin_partial('page-header', [
            'title'    => Lang::get('admin.orders.title'),
            'subtitle' => Lang::get('admin.orders.subtitle'),
            'actions'  => static function (): void { ?>
                <a class="button button--primary" href="/admin/export.php?status=in_production">
                    <?= admin_icon('export') ?><?= e(Lang::get('admin.orders.export_production')) ?>
                </a>
            <?php },
        ]); ?>

        <?php if ($notice !== null) { ?><p class="alert alert--ok" role="status"><?= e($notice) ?></p><?php } ?>
        <?php if ($error !== null) { ?><p class="alert alert--error" role="alert"><?= e($error) ?></p><?php } ?>

        <section class="stat-grid" aria-label="<?= e(Lang::get('admin.orders.summary')) ?>">
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

        <?php admin_partial('filter-bar', [
            'action'    => '/admin/orders.php',
            'keep'      => $tab === 'all' ? [] : ['tab' => $tab],
            'clearHref' => admin_url(['from' => null, 'to' => null, 'q' => null, 'id' => null]),
            'fields'    => [
                ['type' => 'date', 'name' => 'from', 'label' => Lang::get('admin.filters.from'), 'value' => (string) $from],
                ['type' => 'date', 'name' => 'to', 'label' => Lang::get('admin.filters.to'), 'value' => (string) $to],
                ['type' => 'search', 'name' => 'q', 'label' => Lang::get('admin.filters.search'), 'value' => $query,
                 'placeholder' => Lang::get('admin.filters.search_hint')],
            ],
        ]); ?>

        <?php admin_partial('night-panel', [
            'title'     => Lang::get('admin.orders.tab.' . $tab),
            'count'     => $listFailed ? null : Format::number(count($list)),
            'tabs'      => $tabs,
            'tabsLabel' => Lang::get('admin.orders.tabs_label'),
            'body'      => static function () use ($list, $selected, $explicit, $listFailed, $from, $to, $query): void {
                $filtered = $from !== null || $to !== null || $query !== '';
                ?>
                <div class="orders-split<?= $explicit && $selected !== null ? ' has-sheet' : '' ?>">
                    <div class="orders-split__list">
                        <?php if ($listFailed) {
                            admin_partial('state', ['kind' => 'error', 'onDark' => true,
                                'text' => Lang::get('admin.state.error_list'), 'hint' => Lang::get('admin.state.error_hint')]);
                        } elseif ($list === []) {
                            admin_partial('state', ['kind' => 'empty', 'onDark' => true,
                                'text'   => Lang::get($filtered ? 'admin.orders.empty_filtered' : 'admin.orders.empty'),
                                'action' => $filtered ? ['href' => admin_url(['from' => null, 'to' => null, 'q' => null]), 'text' => Lang::get('admin.filters.clear')] : null]);
                        } else { ?>
                            <ul class="order-list">
                                <?php foreach ($list as $order) {
                                    admin_partial('order-row', [
                                        'order'    => $order,
                                        'href'     => admin_url(['id' => (int) $order['id']]) . '#order-detail',
                                        'selected' => $selected !== null && (int) $selected['id'] === (int) $order['id'],
                                    ]);
                                } ?>
                            </ul>
                        <?php } ?>
                    </div>

                    <div class="orders-split__detail">
                        <?php if ($selected !== null) {
                            admin_partial('order-detail', [
                                'order'     => $selected,
                                'explicit'  => $explicit,
                                'closeHref' => admin_url(['id' => null]),
                            ]);
                        } elseif ($explicit && !$listFailed) {
                            admin_partial('state', ['kind' => 'empty', 'onDark' => true, 'text' => Lang::get('admin.orders.not_found'),
                                'action' => ['href' => admin_url(['id' => null]), 'text' => Lang::get('admin.action.back_to_list')]]);
                        } ?>
                    </div>
                </div>
                <?php
            },
        ]); ?>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
