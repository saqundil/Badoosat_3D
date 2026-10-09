<?php

declare(strict_types=1);

/*
 * The home page becomes the owner's, and a product can be called trending.
 *
 * Two changes, one migration, because they exist for the same reason: every
 * editable thing on the landing page was a literal in a template, which means
 * changing the banner over "design it yourself" was a deploy. The shop owner
 * should not need one to swap a photograph.
 *
 * WHY key/value AND NOT COLUMNS
 *
 * A settings row per field is the usual objection -- no types, no constraints,
 * a typo in a key reads as an empty value rather than an error. It is the right
 * shape here anyway: there is exactly one home page, so a single-row table with
 * six columns would carry a primary key that is always 1 and an ALTER for every
 * field the page grows. The keys are declared in one place --
 * HomeSettingsService::DEFAULTS -- and the service refuses anything else, so a
 * typo cannot be written in the first place.
 *
 * The rows are seeded with the text that is currently hardcoded, so the page
 * looks the same the moment this runs and different only once someone edits it.
 *
 * WHY is_trending AND NOT "take the newest eight"
 *
 * Newest is not a merchandising decision, it is a side effect of upload order.
 * The owner picks, and picks the order too: trending_order sorts the row, ties
 * falling back to newest, so a shop that does not care about order gets a
 * sensible one without setting anything.
 *
 * ADDITIVE ONLY. Nothing is dropped, and products.is_trending defaults to 0, so
 * an existing catalogue is unchanged until someone ticks a box.
 */

return static function (PDO $pdo): void {
    $report = static function (string $line): void {
        fwrite(STDOUT, '      ' . $line . PHP_EOL);
    };

    /* -------------------------------------------------------- home_settings */

    $pdo->exec(
        "CREATE TABLE home_settings (
            `key`      VARCHAR(60) NOT NULL PRIMARY KEY,
            -- TEXT, not VARCHAR: the banner's body copy has no useful length
            -- limit, and the rest of this schema uses TEXT for the same reason.
            `value`    TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $report('home_settings created');

    /*
     * The seed is the copy the template carried before this migration. An empty
     * image key is not a missing setting: it is the documented state in which
     * the banner paints its grey gradient instead of a photograph.
     */
    $seed = [
        'printed_card_image' => '',
        'plain_card_image'   => '',
        'promo_image'        => '',
        'promo_title'        => 'صمّم تيشيرتك بنفسك',
        'promo_text'         => 'ارفع تصميمك، ضعه على القميص وشاهده بثلاثة أبعاد قبل أن تطلبه.',
        'promo_button'       => 'ابدأ التصميم',
    ];

    $insert = $pdo->prepare('INSERT INTO home_settings (`key`, `value`) VALUES (?, ?)');

    foreach ($seed as $key => $value) {
        $insert->execute([$key, $value]);
    }

    $report('home_settings seeded with ' . count($seed) . ' keys');

    /* ------------------------------------------------------ products.is_trending */

    $pdo->exec(
        'ALTER TABLE products
            ADD COLUMN is_trending    TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order,
            ADD COLUMN trending_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_trending'
    );

    /*
     * is_active leads the index because every storefront query starts with it.
     * A trending listing reads (is_active = 1 AND is_trending = 1) ORDER BY
     * trending_order, which this covers end to end -- no filesort on the one
     * query the busiest page in the shop runs.
     */
    $pdo->exec(
        'ALTER TABLE products
            ADD INDEX products_trending (is_active, is_trending, trending_order)'
    );

    $report('products.is_trending and products.trending_order added');
    $report('nothing is trending yet: tick products in /admin/home.php');
};
