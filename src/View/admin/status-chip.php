<?php

declare(strict_types=1);

/**
 * An order's status as a pill. The dot carries the family -- accent for an
 * order that still needs work, ink for one that is done, danger for one that
 * was stopped -- and the word carries the meaning, so colour is never alone.
 *
 * @var string $status
 */

use App\Service\OrderService;

$tone = match ($status) {
    'cancelled', 'refunded' => 'stopped',
    'delivered'             => 'done',
    default                 => 'active',
};
?>
<span class="chip chip--<?= e($tone) ?>"><?= e(OrderService::label($status)) ?></span>
