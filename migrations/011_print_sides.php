<?php

declare(strict_types=1);

/*
 * One press file per printed side.
 *
 * The editor keeps a design for the front and one for the back, but a custom
 * design could only carry one press file and print_files allowed one row per
 * design. A customer who designed both sides sent the side on screen to the
 * press and lost the other one without being told.
 *
 * custom_designs.back_original_path holds the back's press file when the two
 * sides differ. It is filled only for an editor design placed on both sides;
 * everywhere else it is NULL and original_path means what it always meant --
 * the one file, printed on the side (or sides) placement names. That is why an
 * upload placed on both sides is untouched: it is the same file twice.
 *
 * print_files.side says which of those a row is. Every existing row becomes
 * 'single', which is exactly what it already was. The unique key moves from
 * the design to the design and side, so a two-sided design gets two rows and
 * a retried print job still cannot write either of them twice. The new key is
 * added before the old one is dropped so the foreign key on custom_design_id
 * always has an index to stand on.
 *
 * Nothing here rewrites a value in an existing row: orders, order lines and
 * designs keep every column they had.
 */

return static function (PDO $pdo): void {
    $report = static function (string $line): void {
        fwrite(STDOUT, '      ' . $line . PHP_EOL);
    };

    $pdo->exec(
        'ALTER TABLE custom_designs
            ADD COLUMN back_original_path VARCHAR(255) NULL AFTER original_path'
    );

    $report('custom_designs.back_original_path added (nullable)');

    $pdo->exec(
        "ALTER TABLE print_files
            ADD COLUMN side ENUM('single','front','back') NOT NULL DEFAULT 'single' AFTER design_id,
            ADD UNIQUE KEY print_files_custom_side (custom_design_id, side)"
    );

    $pdo->exec('ALTER TABLE print_files DROP INDEX print_files_custom');

    $count = (int) $pdo->query('SELECT COUNT(*) FROM print_files')->fetchColumn();

    $report(sprintf("print_files.side added; %d existing row(s) read as 'single'", $count));
    $report('unique key moved from (custom_design_id) to (custom_design_id, side)');
};
