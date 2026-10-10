<?php

declare(strict_types=1);

/**
 * Price and "on sale" by colour and size: the stock matrix's twin.
 *
 * Same grid -- colours down the side, sizes across -- with a money field and
 * an on-sale box in each cell. Prices are shown and posted with their
 * piastres; nothing here rounds. A combination the product does not have is
 * a dash. Posts as price[variantId] and active[variantId].
 *
 * @var string $caption
 * @var array<int,array{id:int,name:string,hex:string}> $colors
 * @var array<int,array{id:int,code:string}>            $sizes
 * @var array<int,array<int,array{id:int,price:string,active:bool,error:?string}|null>> $cells  [colorId][sizeId]
 */

use App\Support\Format;
use App\Support\Lang;

$unit = Format::moneyParts(0);
?>
<div class="stock-matrix price-matrix">
    <table class="stock-matrix__table">
        <caption class="visually-hidden"><?= e($caption) ?></caption>
        <thead>
            <tr>
                <th scope="col"><?= e(Lang::get('admin.form.matrix_colour')) ?></th>
                <?php foreach ($sizes as $size) { ?>
                    <th scope="col"><bdi><?= e($size['code']) ?></bdi></th>
                <?php } ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($colors as $color) { ?>
                <tr>
                    <th scope="row">
                        <span class="swatch swatch--on-light" style="--swatch: <?= e($color['hex']) ?>" aria-hidden="true"></span>
                        <bdi><?= e($color['name']) ?></bdi>
                    </th>
                    <?php foreach ($sizes as $size) {
                        $cell = $cells[$color['id']][$size['id']] ?? null; ?>
                        <td<?= $cell !== null && !$cell['active'] ? ' class="stock-matrix__cell--off"' : '' ?>>
                            <?php if ($cell === null) { ?>
                                <span class="stock-matrix__none" title="<?= e(Lang::get('admin.form.matrix_none')) ?>">—</span>
                            <?php } else {
                                $vid = (int) $cell['id'];
                                $what = ['colour' => $color['name'], 'size' => $size['code']]; ?>
                                <span class="price-cell">
                                    <span class="affix affix--cell<?= $unit['before'] ? ' affix--before' : '' ?>">
                                        <input class="input input--cell" type="text" inputmode="decimal" autocomplete="off"
                                               pattern="[0-9]+([.][0-9]{1,2})?" required id="price-<?= $vid ?>" name="price[<?= $vid ?>]"
                                               value="<?= e($cell['price']) ?>"
                                               aria-label="<?= e(Lang::get('admin.form.matrix_price', $what)) ?>"<?= $cell['error'] !== null ? ' aria-invalid="true"' : '' ?>>
                                        <span class="affix__text" aria-hidden="true"><?= e($unit['symbol']) ?></span>
                                    </span>
                                    <label class="check check--small">
                                        <input type="checkbox" name="active[<?= $vid ?>]" value="1"<?= $cell['active'] ? ' checked' : '' ?>>
                                        <span class="check__box" aria-hidden="true"></span>
                                        <span class="check__text"><?= e(Lang::get('admin.form.matrix_on_sale')) ?></span>
                                        <span class="visually-hidden"><?= e(Lang::get('admin.form.matrix_cell_of', $what)) ?></span>
                                    </label>
                                </span>
                            <?php } ?>
                        </td>
                    <?php } ?>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>
