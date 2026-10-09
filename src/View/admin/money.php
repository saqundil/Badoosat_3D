<?php

declare(strict_types=1);

/**
 * An amount: the figure, and the currency mark smaller and lighter beside it,
 * on whichever side the locale puts it.
 *
 * @var int  $cents  never negative; a deduction passes $minus
 * @var bool $minus
 */

use App\Support\Format;

$minus ??= false;
$money = Format::moneyParts(abs($cents));
?>
<span class="money"><?php if ($money['before']) { ?><span class="money__unit"><?= e($money['symbol']) ?></span> <?php } ?><span class="money__figure"><?= $minus ? '−' : '' ?><?= e($money['figure']) ?></span><?php if (!$money['before']) { ?> <span class="money__unit"><?= e($money['symbol']) ?></span><?php } ?></span>
