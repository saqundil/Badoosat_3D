<?php

declare(strict_types=1);

/**
 * The dashboard's table (template C): sticky header, sortable columns, row
 * actions, bulk selection, pagination, and an empty state instead of nothing.
 *
 * The page owns the data and how each cell reads; this partial owns the
 * structure, the semantics and the behaviour, so every list in the dashboard
 * sorts, selects and collapses the same way.
 *
 * SORTING
 *
 * A sortable column is a button in its header. When the page passes $sortHref
 * the button is a link and the server sorts (paged lists must); otherwise
 * admin-table.js sorts the rows on the page by each cell's data-sort value.
 *
 * NARROW
 *
 * Below 600px of its own width -- a container query, not the viewport -- each
 * row becomes a card and every cell carries its column name from data-label.
 *
 * @var string $id
 * @var string $caption
 * @var array<int,array{key:string,label:string,align?:string,sortable?:bool,primary?:bool,hideLabel?:bool}> $columns
 * @var array<int,array<string,mixed>> $rows        each needs an 'id'
 * @var callable(array,string):void     $cell        prints one cell's content
 * @var ?callable(array,string):string  $sortValue   value to sort a cell by (default: its text)
 * @var ?callable(array):string         $rowLabel    a row's name, for "select <name>" and the menu
 * @var ?callable(array):void           $actions     prints the row menu's items
 * @var ?callable():void                $bulk        prints the bulk bar's buttons; enables selection
 * @var ?string                         $bulkForm    id of the form the selection posts with
 * @var ?string $sort       current sort key (server-sorted lists)
 * @var ?string $sortDir    'asc'|'desc'
 * @var ?callable(string,string):string $sortHref   key, dir => URL (server sorting)
 * @var ?array{text:string,hint?:string,action?:array{href:string,text:string}} $empty
 * @var ?array{page:int,pages:int,href:callable(int):string} $pagination
 */

use App\Support\Format;
use App\Support\Lang;

$sortValue  ??= null;
$rowLabel   ??= null;
$actions    ??= null;
$bulk       ??= null;
$bulkForm   ??= null;
$sort       ??= null;
$sortDir    ??= 'asc';
$sortHref   ??= null;
$empty      ??= null;
$pagination ??= null;
$selectable = $bulk !== null;
?>
<section class="data-table" id="<?= e($id) ?>" data-table<?= $selectable ? ' data-selectable' : '' ?>>
    <?php if ($selectable) { ?>
        <div class="bulk-bar" data-bulk-bar hidden>
            <p class="bulk-bar__count" role="status">
                <?= e(Lang::get('admin.table.selected')) ?> <span class="badge" data-bulk-count>0</span>
            </p>
            <div class="bulk-bar__actions"><?php $bulk(); ?></div>
            <button type="button" class="button button--ghost" data-bulk-clear><?= e(Lang::get('admin.table.clear_selection')) ?></button>
        </div>
    <?php } ?>

    <?php if ($rows === []) { ?>
        <?php admin_partial('state', ['kind' => 'empty'] + ($empty ?? ['text' => Lang::get('admin.table.empty')])); ?>
    <?php } else { ?>
        <div class="data-table__scroll" tabindex="0" role="region" aria-labelledby="<?= e($id) ?>-caption">
            <table class="data-table__table">
                <caption id="<?= e($id) ?>-caption" class="visually-hidden"><?= e($caption) ?></caption>
                <thead>
                    <tr>
                        <?php if ($selectable) { ?>
                            <td class="data-table__select">
                                <label class="check check--bare">
                                    <input type="checkbox" data-select-all aria-label="<?= e(Lang::get('admin.table.select_all')) ?>">
                                    <span class="check__box" aria-hidden="true"></span>
                                </label>
                            </td>
                        <?php } ?>
                        <?php foreach ($columns as $column) {
                            $align  = $column['align'] ?? 'start';
                            $active = $sort === $column['key'];
                            $aria   = $active ? ($sortDir === 'desc' ? 'descending' : 'ascending') : null; ?>
                            <th scope="col" class="data-table__th data-table__th--<?= e($align) ?>"<?= $aria !== null ? ' aria-sort="' . $aria . '"' : '' ?>
                                data-key="<?= e($column['key']) ?>">
                                <?php if (!empty($column['sortable'])) {
                                    $next = $active && $sortDir === 'asc' ? 'desc' : 'asc';
                                    $tag  = $sortHref !== null ? 'a' : 'button'; ?>
                                    <<?= $tag ?> class="sort"<?= $tag === 'a' ? ' href="' . e($sortHref($column['key'], $next)) . '"' : ' type="button" data-sort-by="' . e($column['key']) . '"' ?>>
                                        <span><?= e($column['label']) ?></span>
                                        <span class="sort__icon" aria-hidden="true"></span>
                                    </<?= $tag ?>>
                                <?php } else { ?>
                                    <span<?= !empty($column['hideLabel']) ? ' class="visually-hidden"' : '' ?>><?= e($column['label']) ?></span>
                                <?php } ?>
                            </th>
                        <?php } ?>
                        <?php if ($actions !== null) { ?>
                            <td class="data-table__menu-col"><span class="visually-hidden"><?= e(Lang::get('admin.table.actions')) ?></span></td>
                        <?php } ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row) {
                        $name = $rowLabel !== null ? $rowLabel($row) : (string) $row['id']; ?>
                        <tr data-row="<?= e((string) $row['id']) ?>">
                            <?php if ($selectable) { ?>
                                <td class="data-table__select">
                                    <label class="check check--bare">
                                        <input type="checkbox" name="ids[]" value="<?= e((string) $row['id']) ?>" data-select-row
                                            <?= $bulkForm !== null ? ' form="' . e($bulkForm) . '"' : '' ?>
                                            aria-label="<?= e(Lang::get('admin.table.select_row', ['name' => $name])) ?>">
                                        <span class="check__box" aria-hidden="true"></span>
                                    </label>
                                </td>
                            <?php } ?>
                            <?php foreach ($columns as $column) {
                                $align = $column['align'] ?? 'start';
                                $tag   = !empty($column['primary']) ? 'th' : 'td';
                                $value = $sortValue !== null ? $sortValue($row, $column['key']) : null; ?>
                                <<?= $tag ?><?= $tag === 'th' ? ' scope="row"' : '' ?> class="data-table__cell data-table__cell--<?= e($align) ?>"
                                    data-label="<?= e($column['label']) ?>"<?= $value !== null ? ' data-sort="' . e($value) . '"' : '' ?>>
                                    <?php $cell($row, $column['key']); ?>
                                </<?= $tag ?>>
                            <?php } ?>
                            <?php if ($actions !== null) { ?>
                                <td class="data-table__menu-col">
                                    <details class="row-menu">
                                        <summary class="icon-button icon-button--quiet" aria-label="<?= e(Lang::get('admin.table.row_actions', ['name' => $name])) ?>">
                                            <?= admin_icon('more') ?>
                                        </summary>
                                        <div class="row-menu__list"><?php $actions($row); ?></div>
                                    </details>
                                </td>
                            <?php } ?>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <?php if ($pagination !== null && $pagination['pages'] > 1) {
            $page = $pagination['page'];
            $pages = $pagination['pages']; ?>
            <nav class="pager" aria-label="<?= e(Lang::get('admin.table.pages')) ?>">
                <?php if ($page > 1) { ?>
                    <a class="icon-button icon-button--surface" href="<?= e(($pagination['href'])($page - 1)) ?>" aria-label="<?= e(Lang::get('admin.table.prev')) ?>"><?= admin_icon('back') ?></a>
                <?php } ?>
                <?php for ($n = 1; $n <= $pages; $n++) { ?>
                    <?php if ($n === $page) { ?>
                        <span class="pager__page" aria-current="page"><?= e(Format::number($n)) ?></span>
                    <?php } elseif ($n === 1 || $n === $pages || abs($n - $page) <= 1) { ?>
                        <a class="pager__page" href="<?= e(($pagination['href'])($n)) ?>"><?= e(Format::number($n)) ?></a>
                    <?php } elseif (abs($n - $page) === 2) { ?>
                        <span class="pager__gap" aria-hidden="true">…</span>
                    <?php } ?>
                <?php } ?>
                <?php if ($page < $pages) { ?>
                    <a class="icon-button icon-button--surface" href="<?= e(($pagination['href'])($page + 1)) ?>" aria-label="<?= e(Lang::get('admin.table.next')) ?>"><?= admin_icon('forward') ?></a>
                <?php } ?>
            </nav>
        <?php } ?>
    <?php } ?>
</section>
