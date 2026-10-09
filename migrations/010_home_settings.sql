-- The same change as migrations/010_home_settings.php, for a host where the
-- only way in is phpMyAdmin.
--
-- Run ONE of the two, never both: the PHP migration records itself in the
-- `migrations` table and this file does not, so running this first and the
-- migration runner afterwards would fail on "table already exists". If you run
-- this file, tell the runner it is done:
--
--   INSERT INTO migrations (filename) VALUES ('010_home_settings.php');
--
-- The last statement in this file does exactly that.

SET NAMES utf8mb4;

CREATE TABLE home_settings (
  `key`      VARCHAR(60) NOT NULL PRIMARY KEY,
  `value`    TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An empty image key is not a missing setting: it is the state in which the
-- banner paints its grey gradient instead of a photograph.
INSERT INTO home_settings (`key`, `value`) VALUES
  ('printed_card_image', ''),
  ('plain_card_image',   ''),
  ('promo_image',        ''),
  ('promo_title',        'صمّم تيشيرتك بنفسك'),
  ('promo_text',         'ارفع تصميمك، ضعه على القميص وشاهده بثلاثة أبعاد قبل أن تطلبه.'),
  ('promo_button',       'ابدأ التصميم');

ALTER TABLE products
  ADD COLUMN is_trending    TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order,
  ADD COLUMN trending_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_trending;

ALTER TABLE products
  ADD INDEX products_trending (is_active, is_trending, trending_order);

INSERT INTO migrations (filename) VALUES ('010_home_settings.php');
