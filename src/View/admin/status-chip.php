<?php

declare(strict_types=1);

/**
 * A status as a pill. The dot carries the family -- accent for something that
 * still needs work or is live, ink for done, danger for stopped -- and the word
 * carries the meaning, so colour is never alone.
 *
 * Given an order $status it names and colours itself. Anything else passes its
 * own translated $label and a $tone.
 *
 * @var ?string $status  an order status
 * @var ?string $label   text for a status that is not an order's
 * @var ?string $tone    active|done|stopped, with $label
 */

use App\Service\OrderService;

$status ??= null;
$label  ??= null;
$tone   ??= null;

if ($label === null) {
    $label = OrderService::label((string) $status);
    $tone  = match ($status) {
        'cancelled', 'refunded' => 'stopped',
        'delivered'             => 'done',
        default                 => 'active',
    };
}
?>
<span class="chip chip--<?= e($tone ?? 'active') ?>"><?= e($label) ?></span>
