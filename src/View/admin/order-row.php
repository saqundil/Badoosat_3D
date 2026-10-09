<?php

declare(strict_types=1);

/**
 * One order in the dark list: initials, number and age, status, total.
 *
 * The whole row is the link, so the target is the row and not a word in it.
 * Selected rows take the accent fill and aria-current; the order number, the
 * name and the money are wrapped for direction because each can be the other
 * script from the page around it.
 *
 * @var array<string,mixed> $order   a row from OrderRepository::search()
 * @var string              $href
 * @var bool                $selected
 */

use App\Support\Format;
?>
<li>
    <a class="order-row<?= $selected ? ' is-selected' : '' ?>" href="<?= e($href) ?>"
       <?= $selected ? 'aria-current="true"' : '' ?> data-loads="order-detail">
        <span class="avatar" aria-hidden="true"><bdi><?= e(admin_initials((string) $order['customer_name'])) ?></bdi></span>

        <span class="order-row__main">
            <span class="order-row__id"><bdi>#<?= e($order['order_number']) ?></bdi></span>
            <span class="order-row__meta">
                <bdi class="order-row__name"><?= e($order['customer_name']) ?></bdi>
                <span aria-hidden="true">·</span>
                <time datetime="<?= e(Format::iso((string) $order['created_at'])) ?>"><?= e(Format::ago((string) $order['created_at'])) ?></time>
            </span>
        </span>

        <?php admin_partial('status-chip', ['status' => (string) $order['status']]); ?>

        <span class="order-row__total"><?php admin_partial('money', ['cents' => (int) $order['total_cents']]); ?></span>
    </a>
</li>
