<?php

declare(strict_types=1);

/**
 * The selected order, on the screen's one gradient card.
 *
 * Three facts across the top (the order, when and how it is paid, who it is
 * for), one translucent tile per line with the artwork that will be printed,
 * the production sheet as the dashed tile, and the totals and the status moves
 * along the bottom.
 *
 * ONE ARTWORK PER LINE
 *
 * A line has a single custom design or catalogue design and a placement of
 * front, back or both. "Both" is the same artwork on each side, so it is shown
 * twice, labelled; there is no separate back upload in the schema to show.
 *
 * Under 768px the card is a full-height sheet over the list, but only when an
 * order was chosen ($explicit). The desktop default -- the newest order, shown
 * so the card is never empty -- is not a sheet anyone asked to open.
 *
 * @var array<string,mixed> $order     OrderService::find() + 'prints' keyed by custom_design_id, then side
 * @var bool                $explicit
 * @var string              $closeHref
 */

use App\Service\OrderService;
use App\Support\Csrf;
use App\Support\Format;
use App\Support\Lang;

$locale = Lang::locale();
$id     = (int) $order['id'];
$moves  = OrderService::TRANSITIONS[(string) $order['status']] ?? [];
?>
<article class="order-detail<?= $explicit ? ' order-detail--explicit' : '' ?>" id="order-detail"
         aria-labelledby="order-detail-title" data-loading-target="order-detail" tabindex="-1">
    <a class="icon-button icon-button--on-accent order-detail__close" href="<?= e($closeHref) ?>"
       aria-label="<?= e(Lang::get('admin.action.close')) ?>">
        <?= admin_icon('close') ?>
    </a>

    <header class="order-detail__facts">
        <div class="fact">
            <p class="fact__label"><?= e(Lang::get('admin.orders.detail')) ?></p>
            <h2 class="order-detail__number" id="order-detail-title"><bdi>#<?= e($order['order_number']) ?></bdi></h2>
            <?php admin_partial('status-chip', ['status' => (string) $order['status']]); ?>
        </div>

        <div class="fact">
            <p class="fact__label"><?= e(Lang::get('admin.orders.placed')) ?></p>
            <p class="fact__value">
                <time datetime="<?= e(Format::iso((string) $order['created_at'])) ?>">
                    <?= e(Format::date((string) $order['created_at'], 'datetime')) ?>
                </time>
            </p>
            <p class="fact__sub">
                <?= e(Lang::get('admin.payment.' . $order['payment_driver'])) ?> ·
                <?= e(Lang::get('admin.payment.status.' . $order['payment_status'])) ?>
            </p>
        </div>

        <div class="fact fact--person">
            <p class="fact__label"><?= e(Lang::get('admin.orders.customer')) ?></p>
            <div class="person">
                <span class="avatar avatar--light" aria-hidden="true"><bdi><?= e(admin_initials((string) $order['customer_name'])) ?></bdi></span>
                <div class="person__text">
                    <p class="person__name"><bdi><?= e($order['customer_name']) ?></bdi></p>
                    <p class="person__line"><a href="mailto:<?= e($order['email']) ?>"><bdi><?= e($order['email']) ?></bdi></a></p>
                    <p class="person__line"><a href="tel:<?= e($order['phone_digits']) ?>"><bdi dir="ltr"><?= e($order['phone']) ?></bdi></a></p>
                </div>
            </div>
        </div>
    </header>

    <div class="order-detail__ship">
        <p><span class="fact__label"><?= e(Lang::get('admin.orders.ship_to')) ?></span>
            <bdi><?= e($order['address']) ?></bdi><?= e(Lang::get('admin.list_sep')) ?><bdi><?= e($order['city']) ?></bdi></p>
        <?php if (!empty($order['note'])) { ?>
            <p><span class="fact__label"><?= e(Lang::get('admin.orders.note')) ?></span> <bdi><?= e($order['note']) ?></bdi></p>
        <?php } ?>
    </div>

    <h3 class="visually-hidden"><?= e(Lang::get('admin.orders.items')) ?></h3>
    <ul class="order-detail__items">
        <?php foreach ($order['items'] as $item) {
            $customId = $item['custom_design_id'] === null ? 0 : (int) $item['custom_design_id'];
            // single, or front then back: one download per sheet the press gets.
            $files    = $order['prints'][$customId] ?? [];
            $art      = $item['custom_preview'] ?: ($item['design_thumb'] ?: null);
            $sides    = match ($item['placement']) {
                'both'  => ['front', 'back'],
                'back'  => ['back'],
                default => ['front'],
            };
            $garment = $locale === 'ar' ? $item['garment_name_ar'] : $item['garment_name_en'];
            ?>
            <li class="tile">
                <div class="tile__art">
                    <?php foreach ($sides as $side) { ?>
                        <figure class="art">
                            <?php if ($art !== null) { ?>
                                <img src="<?= e($art) ?>" alt="<?= e(Lang::get('admin.orders.art_alt.' . $side, ['name' => $item['product_name']])) ?>"
                                     width="88" height="88" loading="lazy" decoding="async">
                            <?php } else { ?>
                                <span class="art__plain" style="--swatch: <?= e($item['color_hex']) ?>" aria-hidden="true"></span>
                            <?php } ?>
                            <figcaption><?= e(Lang::get('admin.side.' . $side)) ?></figcaption>
                        </figure>
                    <?php } ?>
                </div>

                <p class="tile__title"><bdi><?= e($item['product_name']) ?></bdi></p>
                <p class="tile__kind">
                    <?php if ($garment) { ?><bdi><?= e($garment) ?></bdi> · <?php } ?>
                    <?= e(match ($item['item_type']) {
                        'ready'  => Lang::get('admin.print.ready', ['name' => (string) $item['design_name']]),
                        'custom' => Lang::get('admin.print.custom_' . ($item['custom_source'] === 'upload' ? 'upload' : 'editor')),
                        default  => Lang::get('admin.print.blank'),
                    }) ?>
                </p>

                <dl class="tile__facts">
                    <div>
                        <dt><?= e(Lang::get('admin.orders.colour')) ?></dt>
                        <dd><span class="swatch" style="--swatch: <?= e($item['color_hex']) ?>" aria-hidden="true"></span><bdi><?= e($item['color_name']) ?></bdi></dd>
                    </div>
                    <div>
                        <dt><?= e(Lang::get('admin.orders.size')) ?></dt>
                        <dd><bdi><?= e($item['size_code']) ?></bdi></dd>
                    </div>
                    <div>
                        <dt><?= e(Lang::get('admin.orders.quantity')) ?></dt>
                        <dd><?= e(Format::number((int) $item['quantity'])) ?></dd>
                    </div>
                </dl>

                <div class="tile__print">
                    <?php if ($item['item_type'] !== 'custom') { ?>
                        <p class="tile__status"><?= e(Lang::get('admin.print.not_needed')) ?></p>
                    <?php } elseif ($files === [] || ($item['custom_back'] && count($files) < 2)) { ?>
                        <p class="tile__status"><?= admin_icon('clock') ?><?= e(Lang::get('admin.print.preparing')) ?></p>
                    <?php } else { ?>
                        <?php foreach ($files as $side => $file) {
                            $suffix = $side === 'single' ? '' : '_' . $side;
                            $query  = 'design=' . $customId . ($side === 'single' ? '' : '&amp;side=' . $side);
                            ?>
                            <a class="button button--on-accent" href="/admin/download.php?<?= $query ?>&amp;type=pdf">
                                <?= admin_icon('download') ?><?= e(Lang::get('admin.print.download' . $suffix)) ?>
                            </a>
                            <a class="icon-button icon-button--on-accent" href="/admin/download.php?<?= $query ?>&amp;type=preview"
                               aria-label="<?= e(Lang::get('admin.print.preview' . $suffix)) ?>" title="<?= e(Lang::get('admin.print.preview' . $suffix)) ?>">
                                <?= admin_icon('eye') ?>
                            </a>
                        <?php } ?>
                        <?php $file = reset($files); ?>
                        <p class="tile__meta"><bdi dir="ltr"><?= e($file['driver']) ?> · <?= e($file['colour_space']) ?> · <?= e(Format::number((int) $file['dpi'])) ?> DPI</bdi></p>
                    <?php } ?>
                </div>
            </li>
        <?php } ?>

        <li>
            <a class="tile tile--dashed" href="/admin/export.php?order=<?= $id ?>">
                <span class="tile__dashed-icon"><?= admin_icon('export') ?></span>
                <?= e(Lang::get('admin.orders.export_sheet')) ?>
            </a>
        </li>
    </ul>

    <footer class="order-detail__foot">
        <dl class="totals">
            <div>
                <dt><?= e(Lang::get('admin.orders.subtotal')) ?></dt>
                <dd><?php admin_partial('money', ['cents' => (int) $order['subtotal_cents']]); ?></dd>
            </div>
            <?php if ((int) $order['discount_cents'] > 0) { ?>
                <div>
                    <dt><?= e(Lang::get('admin.orders.discount')) ?></dt>
                    <dd><?php admin_partial('money', ['cents' => (int) $order['discount_cents'], 'minus' => true]); ?></dd>
                </div>
            <?php } ?>
            <div>
                <dt><?= e(Lang::get('admin.orders.shipping')) ?></dt>
                <dd><?php admin_partial('money', ['cents' => (int) $order['shipping_cents']]); ?></dd>
            </div>
            <div class="totals__total">
                <dt><?= e(Lang::get('admin.orders.total')) ?></dt>
                <dd><?php admin_partial('money', ['cents' => (int) $order['total_cents']]); ?></dd>
            </div>
        </dl>

        <?php if ($moves !== []) { ?>
            <div class="order-detail__actions">
                <?php foreach ($moves as $i => $next) {
                    $stops = $next === 'cancelled' || $next === 'refunded';
                    ?>
                    <form method="post" action="/admin/orders.php" class="inline"
                          <?= $stops ? 'data-confirm="' . e(Lang::get('admin.orders.confirm.' . $next, ['number' => $order['order_number']])) . '"' : '' ?>>
                        <?= Csrf::field() ?>
                        <input type="hidden" name="order_id" value="<?= $id ?>">
                        <input type="hidden" name="status" value="<?= e($next) ?>">
                        <button type="submit" class="button <?= $i === 0 && !$stops ? 'button--on-accent' : 'button--on-accent-quiet' ?>">
                            <?= e(Lang::get('admin.orders.move.' . $next)) ?>
                        </button>
                    </form>
                <?php } ?>
            </div>
        <?php } ?>
    </footer>
</article>
