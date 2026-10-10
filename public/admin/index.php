<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\HeroSlideService;
use App\Support\Auth;
use App\Support\Csrf;
use App\Support\Format;
use App\Support\Lang;

Auth::require();
Csrf::session();

/*
 * The hero slides, in the order the storefront shows them.
 *
 * Reordering works by the move buttons as well as by dragging: a drag is a
 * mouse-only gesture, and the order is what decides which slide a visitor
 * meets first. Either way the new order is saved at once (save.php, action
 * "reorder") and the page says so.
 */

$slides = HeroSlideService::make()->allSlides();
$notice = match (true) {
    isset($_GET['saved'])   => Lang::get('admin.slides.saved'),
    isset($_GET['deleted']) => Lang::get('admin.slides.deleted'),
    default                 => null,
};

$active = count(array_filter($slides, static fn (array $s): bool => (bool) $s['is_active']));

$title   = Lang::get('admin.slides.title');
$current = 'slides';
$toast   = $notice !== null ? ['text' => $notice] : null;

$body = static function () use ($slides, $active): void {
    $name = static fn (array $slide): string => trim((string) ($slide['wordmark'] ?? '')) !== ''
        ? (string) $slide['wordmark']
        : (trim((string) ($slide['tab_word'] ?? '')) !== '' ? (string) $slide['tab_word'] : Lang::get('admin.slides.untitled'));
    $last = count($slides) - 1;
    ?>
    <main class="dash">
        <?php admin_partial('page-header', [
            'title'    => Lang::get('admin.slides.title'),
            'subtitle' => Lang::get('admin.slides.subtitle'),
            'actions'  => static function (): void { ?>
                <a class="button button--primary" href="/admin/edit.php?new=1">
                    <?= admin_icon('plus') ?><?= e(Lang::get('admin.slides.new')) ?>
                </a>
            <?php },
        ]); ?>

        <?php if ($slides !== []) { ?>
            <p class="stock-summary" role="status">
                <span><?= e(Format::plural('admin.slides.count', count($slides))) ?></span>
                <span class="<?= $active === 0 ? 'stock-summary__bad' : '' ?>"><?= e(Format::plural('admin.slides.active_count', $active)) ?></span>
            </p>
        <?php } ?>

        <div class="slide-table" data-reorder data-endpoint="/admin/save.php" data-token="<?= e(Csrf::token()) ?>"
             data-msg-saved="<?= e(Lang::get('admin.slides.order_saved')) ?>"
             data-msg-failed="<?= e(Lang::get('admin.slides.order_failed')) ?>"
             data-msg-moved="<?= e(Lang::get('admin.slides.moved')) ?>">
            <?php admin_partial('data-table', [
                'id'      => 'hero-slides',
                'caption' => Lang::get('admin.slides.title'),
                'columns' => [
                    ['key' => 'order', 'label' => Lang::get('admin.slides.col.order')],
                    ['key' => 'slide', 'label' => Lang::get('admin.slides.col.slide'), 'primary' => true],
                    ['key' => 'cards', 'label' => Lang::get('admin.slides.col.cards')],
                    ['key' => 'model', 'label' => Lang::get('admin.slides.col.model')],
                    ['key' => 'status', 'label' => Lang::get('admin.slides.col.status')],
                ],
                'rows'     => $slides,
                'rowLabel' => $name,
                'cell'     => static function (array $slide, string $key) use ($slides, $name, $last): void {
                    $index = array_search($slide, $slides, true);
                    switch ($key) {
                        case 'order': ?>
                            <span class="reorder">
                                <button type="button" class="icon-button icon-button--quiet" data-move="up"<?= $index === 0 ? ' disabled' : '' ?>
                                        aria-label="<?= e(Lang::get('admin.slides.move_up', ['name' => $name($slide)])) ?>"
                                        title="<?= e(Lang::get('admin.slides.move_up_short')) ?>"><?= admin_icon('arrow-up') ?></button>
                                <span class="reorder__n"><?= e(Format::number((int) $index + 1)) ?></span>
                                <button type="button" class="icon-button icon-button--quiet" data-move="down"<?= $index === $last ? ' disabled' : '' ?>
                                        aria-label="<?= e(Lang::get('admin.slides.move_down', ['name' => $name($slide)])) ?>"
                                        title="<?= e(Lang::get('admin.slides.move_down_short')) ?>"><?= admin_icon('arrow-down') ?></button>
                            </span>
                            <?php break;
                        case 'slide': ?>
                            <span class="cell-media">
                                <span class="cell-media__thumb slide-swatch" style="--swatch: <?= e((string) $slide['garment_color']) ?>; --bg: <?= e(str_starts_with((string) $slide['background_value'], '#') ? (string) $slide['background_value'] : '#141414') ?>" aria-hidden="true"></span>
                                <span class="cell-media__text">
                                    <a class="cell-media__title" href="/admin/edit.php?id=<?= (int) $slide['id'] ?>"><bdi><?= e($name($slide)) ?></bdi></a>
                                    <span class="cell-media__sub"><bdi><?= e((string) ($slide['tab_word'] ?: Lang::get('admin.slides.no_tab'))) ?></bdi></span>
                                </span>
                            </span>
                            <?php break;
                        case 'cards':
                            $filled = count(array_filter($slide['stats'], static fn (array $s): bool => trim((string) ($s['value'] ?? '')) !== ''));
                            echo $slide['show_badges']
                                ? e(Format::plural('admin.slides.cards_count', $filled))
                                : '<span class="cell-media__sub">' . e(Lang::get('admin.slides.cards_hidden')) . '</span>';
                            break;
                        case 'model':
                            echo $slide['has_model']
                                ? '<bdi>' . e((string) ($slide['design_id'] ? Lang::get('admin.slides.model_printed') : Lang::get('admin.slides.model_plain'))) . '</bdi>'
                                : '<span class="cell-media__sub">' . e(Lang::get('admin.slides.model_none')) . '</span>';
                            break;
                        default:
                            admin_partial('status-chip', $slide['is_active']
                                ? ['label' => Lang::get('admin.slides.live'), 'tone' => 'active']
                                : ['label' => Lang::get('admin.slides.paused'), 'tone' => 'done']);
                    }
                },
                'actions' => static function (array $slide) use ($name): void {
                    $id = (int) $slide['id']; ?>
                    <a class="menu-item" href="/admin/edit.php?id=<?= $id ?>"><?= e(Lang::get('admin.slides.edit')) ?></a>
                    <form method="post" action="/admin/save.php" id="slide-toggle-<?= $id ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button type="submit" class="menu-item"><?= e(Lang::get($slide['is_active'] ? 'admin.slides.pause' : 'admin.slides.resume')) ?></button>
                    </form>
                    <form method="post" action="/admin/save.php" id="slide-delete-<?= $id ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button type="submit" class="menu-item menu-item--danger" data-dialog-open="slide-delete-dialog-<?= $id ?>"><?= e(Lang::get('admin.slides.delete')) ?></button>
                    </form>
                <?php },
                'empty' => [
                    'text'   => Lang::get('admin.slides.empty'),
                    'hint'   => Lang::get('admin.slides.empty_hint'),
                    'action' => ['href' => '/admin/edit.php?new=1', 'text' => Lang::get('admin.slides.new')],
                ],
            ]); ?>
        </div>

        <?php foreach ($slides as $slide) {
            admin_partial('form/dialog', [
                'id'      => 'slide-delete-dialog-' . (int) $slide['id'],
                'form'    => 'slide-delete-' . (int) $slide['id'],
                'title'   => Lang::get('admin.slides.delete_q', ['name' => "\u{2068}" . $name($slide) . "\u{2069}"]),
                'text'    => Lang::get('admin.slides.delete_text'),
                'confirm' => Lang::get('admin.slides.delete'),
            ]);
        } ?>
    </main>

    <script type="module" src="/assets/js/admin-list.js?v=<?= e(ASSETS) ?>"></script>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
