<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Service\HeroSlideService;
use App\Support\Auth;
use App\Support\Csrf;

Auth::require();

$slides = HeroSlideService::make()->allSlides();
$notice = match (true) {
    isset($_GET['saved'])   => 'تم الحفظ.',
    isset($_GET['deleted']) => 'تم حذف الشريحة.',
    default                 => null,
};

$title   = 'شرائح الهيرو';
$current = 'slides';

$body = static function () use ($slides, $notice): void {
    ?>
    <main class="admin__body">
        <div class="admin__head">
            <div>
                <h1>شرائح الهيرو</h1>
                <p class="hint">اسحب الشرائح لإعادة ترتيبها. يُحفظ الترتيب فور الإفلات.</p>
            </div>
            <a class="button button--primary" href="/admin/edit.php">شريحة جديدة</a>
        </div>

        <?php if ($notice !== null) { ?>
            <p class="alert alert--ok"><?= e($notice) ?></p>
        <?php } ?>

        <ul class="slides" data-reorder data-endpoint="/admin/save.php" data-token="<?= e(Csrf::token()) ?>">
            <?php foreach ($slides as $slide) { ?>
                <li class="slide" draggable="true" data-id="<?= (int) $slide['id'] ?>">
                    <span class="slide__grip" aria-hidden="true">⠿</span>

                    <span class="slide__swatch" style="background:<?= e($slide['garment_color']) ?>"
                          title="<?= e($slide['garment_color']) ?>"></span>

                    <span class="slide__title">
                        <?= e($slide['wordmark'] ?: 'بلا كلمة') ?>
                        <small><?= e($slide['tab_word'] ?: '—') ?> · <?= count($slide['stats']) ?> بطاقة إحصاء</small>
                    </span>

                    <span class="badge <?= $slide['is_active'] ? 'badge--on' : '' ?>">
                        <?= $slide['is_active'] ? 'نشطة' : 'معطّلة' ?>
                    </span>

                    <span class="slide__actions">
                        <a class="button" href="/admin/edit.php?id=<?= (int) $slide['id'] ?>">تحرير</a>

                        <form method="post" action="/admin/save.php" class="inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $slide['id'] ?>">
                            <button type="submit" class="button"><?= $slide['is_active'] ? 'تعطيل' : 'تفعيل' ?></button>
                        </form>

                        <form method="post" action="/admin/save.php" class="inline"
                              onsubmit="return confirm('حذف هذه الشريحة نهائيًا؟')">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $slide['id'] ?>">
                            <button type="submit" class="button button--danger">حذف</button>
                        </form>
                    </span>
                </li>
            <?php } ?>
        </ul>

        <?php if ($slides === []) { ?>
            <p class="hint">لا توجد شرائح بعد.</p>
        <?php } ?>
    </main>

    <script type="module" src="/assets/js/admin-list.js?v=<?= e(ASSETS) ?>"></script>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
