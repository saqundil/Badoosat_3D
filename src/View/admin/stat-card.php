<?php

declare(strict_types=1);

/**
 * One headline number.
 *
 * The figure is the chart: label, value, and at most one line of context under
 * it -- a trend against a named period, or a plain note. The optional bars are
 * a trend, not a second chart: one series, soft accent, today in the solid
 * accent, and a flex row so time runs in the reading direction without a
 * mirrored drawing. Every bar's value is also in a visually hidden list, so the
 * tooltip enhances and never gates.
 *
 * @var string  $label
 * @var string  $icon      admin_icon() name
 * @var ?string $figure    the number, already formatted; null renders the error state
 * @var ?string $unit      a currency mark or unit, set smaller beside the figure
 * @var bool    $unitBefore
 * @var ?array{direction:'up'|'down'|'flat',text:string} $trend
 * @var ?string $note      a line of context when there is no trend
 * @var list<array{label:string,value:int,text:string,current:bool}> $bars
 * @var ?string $barsLabel accessible name of the bar list
 */

use App\Support\Lang;

$unit       ??= null;
$unitBefore ??= false;
$trend      ??= null;
$note       ??= null;
$bars       ??= [];
$barsLabel  ??= null;
$peak = max(1, ...array_map(static fn (array $bar): int => $bar['value'], $bars ?: [['value' => 0]]));
?>
<article class="stat-card">
    <header class="stat-card__head">
        <h2 class="stat-card__label"><?= e($label) ?></h2>
        <span class="stat-card__icon"><?= admin_icon($icon) ?></span>
    </header>

    <?php if ($figure === null) { ?>
        <?php admin_partial('state', ['kind' => 'error', 'compact' => true, 'text' => Lang::get('admin.state.error_card')]) ?>
    <?php } else { ?>
        <p class="stat-card__figure">
            <?php if ($unit !== null && $unitBefore) { ?><span class="stat-card__unit"><?= e($unit) ?></span><?php } ?>
            <span class="stat-card__value"><?= e($figure) ?></span>
            <?php if ($unit !== null && !$unitBefore) { ?><span class="stat-card__unit"><?= e($unit) ?></span><?php } ?>
        </p>

        <?php if ($trend !== null) { ?>
            <p class="stat-card__trend stat-card__trend--<?= e($trend['direction']) ?>">
                <?php if ($trend['direction'] !== 'flat') { ?>
                    <span class="stat-card__arrow" aria-hidden="true"><?= $trend['direction'] === 'up' ? '↑' : '↓' ?></span>
                <?php } ?>
                <?= e($trend['text']) ?>
            </p>
        <?php } elseif ($note !== null) { ?>
            <p class="stat-card__note"><?= e($note) ?></p>
        <?php } ?>

        <?php if ($bars !== []) { ?>
            <ol class="stat-card__bars" aria-label="<?= e((string) $barsLabel) ?>">
                <?php foreach ($bars as $bar) { ?>
                    <li class="stat-card__bar<?= $bar['current'] ? ' is-current' : '' ?>"
                        style="--bar: <?= round($bar['value'] / $peak, 3) ?>"
                        data-tip="<?= e($bar['label'] . ' · ' . $bar['text']) ?>">
                        <span class="visually-hidden"><?= e($bar['label']) ?>: <?= e($bar['text']) ?></span>
                    </li>
                <?php } ?>
            </ol>
        <?php } ?>
    <?php } ?>
</article>
