<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Print\PrintDriverFactory;
use App\Support\Auth;
use App\Support\Config;
use App\Support\Db;
use App\Support\UploadLimit;

Auth::require();

$drivers  = PrintDriverFactory::availability();
$selected = PrintDriverFactory::make();
$uploadLimits = UploadLimit::sources();

$functions = [];

foreach (['proc_open', 'exec', 'shell_exec', 'popen'] as $function) {
    $disabled = in_array($function, array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
    $functions[$function] = function_exists($function) && !$disabled;
}

try {
    $queue = Db::pdo()->query('SELECT COUNT(*) AS pending FROM jobs')->fetch()['pending'] ?? 0;
    $failed = Db::pdo()->query('SELECT COUNT(*) AS failed FROM failed_jobs')->fetch()['failed'] ?? 0;
    $dbOk = true;
} catch (PDOException) {
    $queue = $failed = 0;
    $dbOk = false;
}

$title   = 'حالة النظام';
$current = 'system';

$body = static function () use ($drivers, $selected, $functions, $queue, $failed, $dbOk, $uploadLimits): void {
    ?>
    <main class="admin__body">
        <h1>حالة النظام</h1>
        <p class="hint">
            ما تراه هنا هو ما تكتشفه المنظومة عند الإقلاع. تبديل
            <code>print.driver</code> في <code>config/print.php</code> يغيّر المعالجة دون لمس أي خدمة.
        </p>

        <div class="panel" style="margin-bottom:18px">
            <h2>سائق الطباعة</h2>
            <table class="table">
                <thead><tr><th>السائق</th><th>متاح هنا</th><th>يحوّل الألوان</th></tr></thead>
                <tbody>
                    <?php foreach ($drivers as $name => $available) { ?>
                        <tr>
                            <td>
                                <?= e($name) ?>
                                <?php if ($name === $selected->name()) { ?>
                                    <span class="badge badge--on">المُختار</span>
                                <?php } ?>
                            </td>
                            <td><?= $available ? '<span class="badge badge--on">نعم</span>' : '<span class="badge">لا</span>' ?></td>
                            <td><?= $name === 'passthrough' ? 'لا — RGB إلى المطبعة' : 'نعم — CMYK' ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <p class="hint">
                الإعداد الحالي: <code><?= e((string) Config::get('print.driver')) ?></code> ·
                المستخدم فعليًا: <code><?= e($selected->name()) ?></code> ·
                امتداد imagick: <?= extension_loaded('imagick') ? 'محمّل' : 'غير محمّل' ?>
            </p>
        </div>

        <div class="panel" style="margin-bottom:18px">
            <h2>حد الرفع</h2>
            <table class="table">
                <thead><tr><th>المصدر</th><th>الحد</th></tr></thead>
                <tbody>
                    <?php foreach ($uploadLimits as $source => $bytes) { ?>
                        <tr>
                            <td><code><?= e($source) ?></code></td>
                            <td><?= e(UploadLimit::mebibytes($bytes)) ?> ميغابايت</td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <p class="hint"><strong>الحد الفعلي:</strong> <?= e(UploadLimit::mebibytes(UploadLimit::bytes())) ?> ميغابايت، وهو أصغر الحدود الثلاثة.</p>
        </div>

        <div class="panel" style="margin-bottom:18px">
            <h2>دوال تشغيل العمليات</h2>
            <table class="table">
                <thead><tr><th>الدالة</th><th>متاحة</th></tr></thead>
                <tbody>
                    <?php foreach ($functions as $name => $available) { ?>
                        <tr>
                            <td><code><?= e($name) ?></code></td>
                            <td><?= $available ? 'نعم' : '<span class="badge">محجوبة</span>' ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <p class="hint">لا يعتمد أي مسار إلزامي على هذه الدوال؛ حجبها يُسقط المنظومة إلى <code>passthrough</code> فقط.</p>
        </div>

        <div class="panel">
            <h2>الطابور</h2>
            <p class="hint">
                <?php if ($dbOk) { ?>
                    <?= (int) $queue ?> مهمة في الانتظار · <?= (int) $failed ?> مهمة فاشلة.
                <?php } else { ?>
                    تعذّر الاتصال بقاعدة البيانات.
                <?php } ?>
            </p>
            <p class="hint">
                على الاستضافة المشتركة: استدعِ <code>/cron.php?key=…</code> كل دقيقة من لوحة الاستضافة.
                على VPS: <code>php public/cron.php --loop</code> تحت systemd.
            </p>
        </div>
    </main>
    <?php
};

require dirname(__DIR__, 2) . '/src/View/admin.php';
