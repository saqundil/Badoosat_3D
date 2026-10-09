<?php

declare(strict_types=1);

/**
 * The settings layout (template E): an index of sections that stays in view,
 * and the sections themselves as grouped cards.
 *
 * Each card has a title, a sentence on what the section controls, and its
 * fields. The page wraps this in its own <form> (or several) and adds the save
 * bar; a read-only page such as System status simply has no fields to save.
 *
 * @var string $label   accessible name of the index
 * @var array<string,array{title:string,help?:string,body:callable,badge?:array{text:string,kind:string}}> $sections
 */
?>
<div class="settings">
    <nav class="settings__index" aria-label="<?= e($label) ?>" data-settings-index>
        <ol class="settings__index-list">
            <?php foreach ($sections as $id => $section) { ?>
                <li><a class="settings__index-link" href="#<?= e($id) ?>"><?= e($section['title']) ?></a></li>
            <?php } ?>
        </ol>
    </nav>

    <div class="settings__sections">
        <?php foreach ($sections as $id => $section) { ?>
            <section class="settings__card" id="<?= e($id) ?>" aria-labelledby="<?= e($id) ?>-title">
                <header class="settings__head">
                    <h2 class="settings__title" id="<?= e($id) ?>-title"><?= e($section['title']) ?></h2>
                    <?php if (!empty($section['badge'])) {
                        admin_partial('status-chip', ['label' => $section['badge']['text'], 'tone' => $section['badge']['kind']]);
                    } ?>
                </header>
                <?php if (!empty($section['help'])) { ?>
                    <p class="settings__help"><?= e($section['help']) ?></p>
                <?php } ?>
                <div class="settings__body"><?php ($section['body'])(); ?></div>
            </section>
        <?php } ?>
    </div>
</div>
