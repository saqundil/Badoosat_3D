<?php

declare(strict_types=1);

/**
 * Stock by colour and size, edited as one grid.
 *
 * Colours down the side, sizes across, a total per colour and per size. The
 * totals are worked out here for the first paint and kept current by
 * admin-forms.js; the server never reads them back -- only the cells post.
 * A combination the cut does not offer is shown as a dash, not as an input.
 *
 * @var string $name                        posts as name[colorId][sizeId]
 * @var string $caption
 * @var array<int,array{id:int,name:string,hex:string}> $colors
 * @var array<int,array{id:int,code:string}>            $sizes
 * @var array<int,array<int,int|null>>                  $values  [colorId][sizeId] => stock, null = not offered
 */

use App\Support\Format;
use App\Support\Lang;

$colTotals = [];
$grand = 0;
?>
<div class="stock-matrix" data-stock-matrix>
    <table class="stock-matrix__table">
        <caption class="visually-hidden"><?= e($caption) ?></caption>
        <thead>
            <tr>
                <th scope="col"><?= e(Lang::get('admin.form.matrix_colour')) ?></th>
                <?php foreach ($sizes as $size) { ?>
                    <th scope="col"><bdi><?= e($size['code']) ?></bdi></th>
                <?php } ?>
                <th scope="col" class="stock-matrix__total"><?= e(Lang::get('admin.form.matrix_total')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($colors as $color) {
                $rowTotal = 0; ?>
                <tr>
                    <th scope="row">
                        <span class="swatch" style="--swatch: <?= e($color['hex']) ?>" aria-hidden="true"></span>
                        <bdi><?= e($color['name']) ?></bdi>
                    </th>
                    <?php foreach ($sizes as $size) {
                        $v = $values[$color['id']][$size['id']] ?? null; ?>
                        <td>
                            <?php if ($v === null) { ?>
                                <span class="stock-matrix__none" title="<?= e(Lang::get('admin.form.matrix_none')) ?>">—</span>
                            <?php } else {
                                $rowTotal += $v;
                                $colTotals[$size['id']] = ($colTotals[$size['id']] ?? 0) + $v; ?>
                                <input class="input input--cell" type="number" min="0" step="1" inputmode="numeric"
                                       name="<?= e($name) ?>[<?= (int) $color['id'] ?>][<?= (int) $size['id'] ?>]"
                                       value="<?= (int) $v ?>" data-row="<?= (int) $color['id'] ?>" data-col="<?= (int) $size['id'] ?>"
                                       aria-label="<?= e(Lang::get('admin.form.matrix_cell', ['colour' => $color['name'], 'size' => $size['code']])) ?>">
                            <?php } ?>
                        </td>
                    <?php }
                    $grand += $rowTotal; ?>
                    <td class="stock-matrix__total" data-row-total="<?= (int) $color['id'] ?>"><?= e(Format::number($rowTotal)) ?></td>
                </tr>
            <?php } ?>
        </tbody>
        <tfoot>
            <tr>
                <th scope="row"><?= e(Lang::get('admin.form.matrix_total')) ?></th>
                <?php foreach ($sizes as $size) { ?>
                    <td class="stock-matrix__total" data-col-total="<?= (int) $size['id'] ?>"><?= e(Format::number($colTotals[$size['id']] ?? 0)) ?></td>
                <?php } ?>
                <td class="stock-matrix__total stock-matrix__grand" data-grand-total><?= e(Format::number($grand)) ?></td>
            </tr>
        </tfoot>
    </table>
</div>
