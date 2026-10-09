<?php

declare(strict_types=1);

/**
 * The studio shell (template D): sections on one side, the live preview in the
 * middle, the selected item's properties on the other, a floating toolbar on
 * top. The same three-pane language as the customer editor.
 *
 * The shell draws the panes and the toolbar and nothing else. Every control in
 * it is optional and appears only when the page asks for it, because each one
 * must do something real on that page: a page without undo gets no undo button.
 *
 * SELECTION
 *
 * An element with data-studio-item="<key>" in the layers pane and one with the
 * same key in the canvas are the same thing. admin-studio.js keeps them in
 * step and fires a "studio:select" event with the key, so the page can show
 * that item's fields. A preview inside an iframe takes part through
 * postMessage({studio: 'select', key}).
 *
 * @var string   $id
 * @var string   $label        accessible name of the whole editor
 * @var callable $layers       prints the layers/sections list
 * @var ?string  $layersTitle
 * @var callable $canvas       prints the preview (an iframe, or markup)
 * @var array<string,array{label:string,body:callable}> $panels  properties tabs
 * @var array<string,array{label:string,width:int}>     $devices preview widths; [] = none
 * @var array<string,string>   $languages  code => label for the preview language; [] = none
 * @var ?string  $saveForm     id of the form the Save button submits; null = no Save
 * @var bool     $history      undo/redo buttons; the page handles studio:undo / studio:redo
 * @var bool     $zoom
 * @var ?callable $tools       extra toolbar controls the page owns (camera views, …)
 * @var ?callable $layersFoot  printed under the layers list, outside the listbox
 */

use App\Support\Lang;

$layersTitle ??= Lang::get('admin.studio.layers');
$devices     ??= [];
$languages   ??= [];
$saveForm    ??= null;
$history     ??= false;
$zoom        ??= false;
$tools       ??= null;
$layersFoot  ??= null;

$firstDevice = array_key_first($devices);
$firstLang   = array_key_first($languages);
?>
<div class="studio" id="<?= e($id) ?>" data-studio role="region" aria-label="<?= e($label) ?>">
    <div class="studio__toolbar" role="toolbar" aria-label="<?= e(Lang::get('admin.studio.toolbar')) ?>">
        <button type="button" class="icon-button icon-button--quiet studio__toggle" data-studio-toggle="layers"
                aria-controls="<?= e($id) ?>-layers" aria-expanded="true" title="<?= e(Lang::get('admin.studio.toggle_layers')) ?>"
                aria-label="<?= e(Lang::get('admin.studio.toggle_layers')) ?>"><?= admin_icon('panel') ?></button>

        <?php if ($devices !== []) { ?>
            <div class="segmented segmented--small" role="group" aria-label="<?= e(Lang::get('admin.studio.device')) ?>">
                <?php foreach ($devices as $key => $device) { ?>
                    <button type="button" class="segmented__option" data-studio-device="<?= (int) $device['width'] ?>"
                            aria-pressed="<?= $key === $firstDevice ? 'true' : 'false' ?>"><?= e($device['label']) ?></button>
                <?php } ?>
            </div>
        <?php } ?>

        <?php if ($languages !== []) { ?>
            <div class="segmented segmented--small" role="group" aria-label="<?= e(Lang::get('admin.studio.preview_lang')) ?>">
                <?php foreach ($languages as $code => $name) { ?>
                    <button type="button" class="segmented__option" data-studio-lang="<?= e($code) ?>" lang="<?= e($code) ?>"
                            aria-pressed="<?= $code === $firstLang ? 'true' : 'false' ?>"><?= e($name) ?></button>
                <?php } ?>
            </div>
        <?php } ?>

        <?php if ($history) { ?>
            <span class="studio__group">
                <button type="button" class="icon-button icon-button--quiet" data-studio-undo disabled
                        aria-label="<?= e(Lang::get('admin.studio.undo')) ?>" title="<?= e(Lang::get('admin.studio.undo')) ?>" aria-keyshortcuts="Control+Z"><?= admin_icon('undo') ?></button>
                <button type="button" class="icon-button icon-button--quiet" data-studio-redo disabled
                        aria-label="<?= e(Lang::get('admin.studio.redo')) ?>" title="<?= e(Lang::get('admin.studio.redo')) ?>" aria-keyshortcuts="Control+Shift+Z"><?= admin_icon('redo') ?></button>
            </span>
        <?php } ?>

        <?php if ($zoom) { ?>
            <span class="select select--small">
                <label class="visually-hidden" for="<?= e($id) ?>-zoom"><?= e(Lang::get('admin.studio.zoom')) ?></label>
                <select class="input input--select" id="<?= e($id) ?>-zoom" data-studio-zoom>
                    <option value="fit"><?= e(Lang::get('admin.studio.zoom_fit')) ?></option>
                    <option value="1">100%</option>
                    <option value="0.75">75%</option>
                    <option value="0.5">50%</option>
                </select>
            </span>
        <?php } ?>

        <?php if ($tools !== null) { $tools(); } ?>

        <span class="studio__spacer"></span>

        <button type="button" class="icon-button icon-button--quiet studio__toggle" data-studio-toggle="panel"
                aria-controls="<?= e($id) ?>-panel" aria-expanded="true" title="<?= e(Lang::get('admin.studio.toggle_panel')) ?>"
                aria-label="<?= e(Lang::get('admin.studio.toggle_panel')) ?>"><?= admin_icon('panel') ?></button>
    </div>

    <aside class="studio__pane studio__pane--layers" id="<?= e($id) ?>-layers" aria-labelledby="<?= e($id) ?>-layers-title">
        <h2 class="studio__pane-title" id="<?= e($id) ?>-layers-title"><?= e($layersTitle) ?></h2>
        <div class="studio__layers" role="listbox" aria-labelledby="<?= e($id) ?>-layers-title" data-studio-layers><?php $layers(); ?></div>
        <?php if ($layersFoot !== null) { $layersFoot(); } ?>
    </aside>

    <div class="studio__stage" data-studio-stage>
        <div class="studio__frame" data-studio-frame<?= $firstDevice !== null ? ' style="--frame-w: ' . (int) $devices[$firstDevice]['width'] . 'px"' : '' ?>>
            <?php $canvas(); ?>
        </div>
    </div>

    <aside class="studio__pane studio__pane--panel" id="<?= e($id) ?>-panel" aria-label="<?= e(Lang::get('admin.studio.properties')) ?>">
        <?php /* The one primary action sits at the head of the properties, as the
                 reference puts its main button at the top of the right pane. */ ?>
        <?php if ($saveForm !== null) { ?>
            <button type="submit" class="button button--primary studio__save" form="<?= e($saveForm) ?>"><?= e(Lang::get('admin.form.save')) ?></button>
        <?php } ?>
        <?php if (count($panels) > 1) { ?>
            <div class="segmented studio__tabs" role="tablist" aria-label="<?= e(Lang::get('admin.studio.properties')) ?>">
                <?php $i = 0;
                foreach ($panels as $key => $panel) { ?>
                    <button type="button" role="tab" class="segmented__option" id="<?= e($id) ?>-tab-<?= e($key) ?>"
                            aria-controls="<?= e($id) ?>-tp-<?= e($key) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                            tabindex="<?= $i === 0 ? '0' : '-1' ?>" data-studio-tab><?= e($panel['label']) ?></button>
                <?php $i++;
                } ?>
            </div>
        <?php } ?>
        <?php $i = 0;
        foreach ($panels as $key => $panel) { ?>
            <div class="studio__tabpanel" id="<?= e($id) ?>-tp-<?= e($key) ?>"<?= count($panels) > 1 ? ' role="tabpanel" aria-labelledby="' . e($id) . '-tab-' . e($key) . '"' : '' ?><?= $i > 0 ? ' hidden' : '' ?>>
                <?php ($panel['body'])(); ?>
            </div>
        <?php $i++;
        } ?>
    </aside>
</div>
