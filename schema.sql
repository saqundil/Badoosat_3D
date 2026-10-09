-- mysql -u root tshirt_store < schema.sql
--
-- TEXT rather than JSON throughout: shared hosting may be MariaDB or MySQL 5.6,
-- and neither is guaranteed to have a JSON column type.
--
-- The central idea of the model: a blank shirt is a product, a ready-made
-- design is something printed ON one, and a customer's own artwork is a third
-- thing again. Making every design its own product would multiply the stock
-- table by the size of the catalogue for no reason -- stock belongs to the
-- garment, in a colour and a size, and nowhere else. Designs are printed to
-- order and have no stock at all.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS failed_jobs, jobs, print_files, order_items, orders,
    addresses, customers, cart_items, carts, custom_designs, design_products,
    designs, product_colors, product_variants, product_images, products,
    garment_colors, garment_sizes, garments, sizes, colors,
    hero_slides, home_settings, migrations;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------------ the hero

CREATE TABLE hero_slides (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  -- A slide may show no garment at all: then the background and the words are
  -- the whole slide, and not one byte of three.js is fetched for it.
  has_model         TINYINT(1) NOT NULL DEFAULT 1,
  -- A key in config/hero-models.php, never a path. The registry is the only
  -- set of .glb files the hero can reach.
  model_key         VARCHAR(40) NOT NULL DEFAULT 'tshirt',
  color_id          SMALLINT UNSIGNED NULL,
  design_id         INT UNSIGNED NULL,
  -- The cart's vocabulary, in CartService::PLACEMENTS. VARCHAR rather than a
  -- second ENUM so that list stays the only place the values are written.
  placement         VARCHAR(10) NOT NULL DEFAULT 'front',
  -- The two stat cards ("240 gsm", "50 washes").
  show_badges       TINYINT(1) NOT NULL DEFAULT 1,
  -- Whether this slide's layers drift against pointer movement. A reduced
  -- motion preference overrides it; see hero.js.
  parallax_enabled  TINYINT(1) NOT NULL DEFAULT 1,
  -- Superseded by model_key / color_id / design_id, kept as the fallback for
  -- slides whose artwork was a one-off upload that matches no catalogue design.
  model_path        VARCHAR(255) NOT NULL,
  poster_path       VARCHAR(255) NULL,
  garment_color     CHAR(7) NOT NULL DEFAULT '#E7E4DC',
  -- Artwork laid onto the hero's shirt, when a slide shows a printed one.
  design_image_path VARCHAR(255) NULL,
  background_value  VARCHAR(255) NOT NULL DEFAULT '#141414',
  tab_word          VARCHAR(60) NULL,
  wordmark          VARCHAR(60) NULL,
  tagline           VARCHAR(255) NULL,
  stats             TEXT NULL,
  layout            TEXT NULL,
  cta_label         VARCHAR(60) NULL,
  cta_url           VARCHAR(255) NULL,
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (is_active, sort_order),
  INDEX hero_slides_color (color_id),
  INDEX hero_slides_design (design_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The two keys the table above needs are added once their targets exist: the
-- hero is declared first because it is the first thing on the page, and
-- `colors` and `designs` belong with the catalogue further down.
-- SET NULL, not CASCADE: retiring a colour must not delete a hero slide.

-- ------------------------------------------------------------- the catalogue

-- Reference tables, not free text on the product. A colour has one hex
-- everywhere it appears, and a size carries the centimetres the size chart
-- prints -- the single largest cause of returns is a size guessed from a letter.
CREATE TABLE colors (
  id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(60) NOT NULL,
  hex         CHAR(7) NOT NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY colors_hex (hex)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sizes (
  id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(10) NOT NULL,
  label       VARCHAR(40) NOT NULL,
  chest_cm    DECIMAL(4,1) NOT NULL,
  length_cm   DECIMAL(4,1) NOT NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY sizes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The blank garment. No colour, no size and no price live here: those are the
-- variant's, because that is the thing that is actually in stock.
CREATE TABLE migrations (
  filename     VARCHAR(255) NOT NULL PRIMARY KEY,
  executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (filename) VALUES
  ('001_product_management.php'),
  ('002_product_design_placement.php'),
  ('003_design_layers.php'),
  ('004_hero_slide_model.php'),
  ('005_hero_slide_parallax.php'),
  ('006_protect_order_history.php'),
  ('007_variant_deactivation_reason.php'),
  ('008_garment_types.php'),
  ('009_garment_base_product.php'),
  ('010_home_settings.php');

-- ------------------------------------------------------- the home page's copy
--
-- One row per editable thing on the landing page: the two category photographs,
-- and the banner over the design editor. key/value rather than one row of six
-- columns, because there is exactly one home page -- a single-row table would
-- carry a primary key that is always 1 and an ALTER for every field the page
-- grows. The permitted keys are declared in HomeSettingsService::DEFAULTS and
-- the service refuses anything else, so a typo cannot be written.
--
-- An empty image value is not a missing setting: it is the state in which the
-- section paints its grey gradient instead of a photograph.
CREATE TABLE home_settings (
  `key`      VARCHAR(60) NOT NULL PRIMARY KEY,
  `value`    TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO home_settings (`key`, `value`) VALUES
  ('printed_card_image', ''),
  ('plain_card_image',   ''),
  ('promo_image',        ''),
  ('promo_title',        'صمّم تيشيرتك بنفسك'),
  ('promo_text',         'ارفع تصميمك، ضعه على القميص وشاهده بثلاثة أبعاد قبل أن تطلبه.'),
  ('promo_button',       'ابدأ التصميم');

-- ------------------------------------------------------------ garment types

-- The cuts the shop sells. This is the merchandising half of "garment": what a
-- cut is called, which model shows it, how big its print area is, and which
-- colours and sizes it is made in. The other half -- the UV rectangles artwork
-- is fitted into -- stays in config/garments.php, because it is measured
-- against a mesh and no admin screen should be able to change it.
--
-- `model_key` is a key in config/garment-models.php, never a path. That
-- registry is the only set of .glb files a type can reach, and it also names
-- the config/garments.php entry holding the geometry for that mesh.
CREATE TABLE garments (
  id              SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`           VARCHAR(60) NOT NULL,
  name_ar         VARCHAR(120) NOT NULL,
  name_en         VARCHAR(120) NOT NULL,
  model_key       VARCHAR(60) NOT NULL,
  -- What a CUSTOM order of this cut is sold as. The 3D editor is product-first
  -- -- a cart line points at a variant, and price and stock live there -- so a
  -- cut names the product its custom orders become. Null means the cut is not
  -- offered in the editor at all; the admin screen says so.
  base_product_id INT UNSIGNED NULL,
  -- Where artwork is placed, in centimetres. Not the printable panel: that is
  -- the limit of where ink CAN go and lives with the geometry.
  print_area_w_cm DECIMAL(5,1) NOT NULL DEFAULT 28.0,
  print_area_h_cm DECIMAL(5,1) NOT NULL DEFAULT 35.0,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY garments_key (`key`),
  INDEX garments_listing (is_active, sort_order),
  INDEX garments_base_product (base_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RESTRICT on the reference keys: retiring a colour from the palette must not
-- silently empty a cut of its colours.
CREATE TABLE garment_colors (
  garment_id  SMALLINT UNSIGNED NOT NULL,
  color_id    SMALLINT UNSIGNED NOT NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (garment_id, color_id),
  INDEX garment_colors_color (color_id),
  CONSTRAINT garment_colors_garment_fk FOREIGN KEY (garment_id) REFERENCES garments (id) ON DELETE CASCADE,
  CONSTRAINT garment_colors_color_fk   FOREIGN KEY (color_id)   REFERENCES colors (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE garment_sizes (
  garment_id  SMALLINT UNSIGNED NOT NULL,
  size_id     SMALLINT UNSIGNED NOT NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (garment_id, size_id),
  INDEX garment_sizes_size (size_id),
  CONSTRAINT garment_sizes_garment_fk FOREIGN KEY (garment_id) REFERENCES garments (id) ON DELETE CASCADE,
  CONSTRAINT garment_sizes_size_fk    FOREIGN KEY (size_id)    REFERENCES sizes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug              VARCHAR(120) NOT NULL,
  name              VARCHAR(160) NOT NULL,
  summary           VARCHAR(255) NULL,
  description       TEXT NULL,
  fabric            VARCHAR(120) NULL,
  fit               VARCHAR(120) NULL,
  care              VARCHAR(500) NULL,
  print_method      VARCHAR(60) NOT NULL DEFAULT 'DTF',
  -- The geometry key, still, because Garment::make() reads it. Derived from
  -- the type's model on every save rather than chosen by hand.
  garment           VARCHAR(40) NOT NULL DEFAULT 'tshirt',
  garment_id        SMALLINT UNSIGNED NULL,
  -- The print. Its presence IS the distinction between a plain piece and a
  -- printed one: there is no type column saying which, because two fields that
  -- must agree eventually do not.
  artwork_path      VARCHAR(255) NULL,
  artwork_x         TINYINT UNSIGNED NOT NULL DEFAULT 50,
  artwork_y         TINYINT UNSIGNED NOT NULL DEFAULT 50,
  artwork_scale     SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  artwork_side      VARCHAR(10) NOT NULL DEFAULT 'front',
  -- Superseded by artwork_path and kept truthful by the save. Phase 4 drops
  -- these four along with the design tables.
  presentation_type VARCHAR(40) NOT NULL DEFAULT 'standard',
  design_x          TINYINT UNSIGNED NOT NULL DEFAULT 50,
  design_y          TINYINT UNSIGNED NOT NULL DEFAULT 50,
  design_scale      SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  show_3d           TINYINT(1) NOT NULL DEFAULT 1,
  customization_enabled TINYINT(1) NOT NULL DEFAULT 1,
  base_price_cents  INT UNSIGNED NOT NULL,
  image_path        VARCHAR(255) NULL,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  -- Picked by the owner, not derived. "Newest" is upload order, which is not a
  -- merchandising decision; trending_order lets them say which comes first and
  -- falls back to newest when they leave every row on 0.
  is_trending       TINYINT(1) NOT NULL DEFAULT 0,
  trending_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  sold_count        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY products_slug (slug),
  CONSTRAINT products_garment_fk FOREIGN KEY (garment_id) REFERENCES garments (id),
  INDEX products_listing (is_active, sort_order),
  INDEX products_bestselling (is_active, sold_count),
  INDEX products_price (is_active, base_price_cents),
  -- Covers the home page's one listing query end to end: no filesort on the
  -- busiest page in the shop.
  INDEX products_trending (is_active, is_trending, trending_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per buyable thing. Stock lives here and only here.
CREATE TABLE product_variants (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id   INT UNSIGNED NOT NULL,
  color_id     SMALLINT UNSIGNED NOT NULL,
  size_id      SMALLINT UNSIGNED NOT NULL,
  sku          VARCHAR(40) NOT NULL,
  price_cents  INT UNSIGNED NOT NULL,
  stock        INT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  -- Set when THIS row was switched off by deactivating the whole product, so
  -- reactivating the product raises only these and leaves a colour the owner
  -- turned off by hand exactly where they left it.
  deactivated_by_product TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY variants_sku (sku),
  UNIQUE KEY variants_combination (product_id, color_id, size_id),
  INDEX variants_lookup (product_id, color_id, size_id),
  CONSTRAINT variants_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT variants_color   FOREIGN KEY (color_id)   REFERENCES colors (id),
  CONSTRAINT variants_size    FOREIGN KEY (size_id)    REFERENCES sizes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The colours this product is offered in: a subset of what its TYPE is cut in.
-- Stored rather than derived from the active variants, because a colour whose
-- every variant an owner switched off by hand is still a colour the product is
-- meant to come in.
CREATE TABLE product_colors (
  product_id  INT UNSIGNED NOT NULL,
  color_id    SMALLINT UNSIGNED NOT NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, color_id),
  INDEX product_colors_color (color_id),
  CONSTRAINT product_colors_product_fk FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT product_colors_color_fk   FOREIGN KEY (color_id)   REFERENCES colors (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Listing and product photography. `width` and `height` are stored so the page
-- can print them on the <img> and reserve the box before the file arrives.
CREATE TABLE product_images (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  INT UNSIGNED NOT NULL,
  color_id    SMALLINT UNSIGNED NULL,
  path        VARCHAR(255) NOT NULL,
  image_type  VARCHAR(40) NOT NULL DEFAULT 'product',
  primary_for INT UNSIGNED NULL,
  width_px    SMALLINT UNSIGNED NOT NULL,
  height_px   SMALLINT UNSIGNED NOT NULL,
  mime_type   VARCHAR(100) NULL,
  file_size   INT UNSIGNED NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY product_images_one_primary (primary_for),
  INDEX product_images_product (product_id, sort_order),
  CONSTRAINT product_images_product_fk FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT product_images_color_fk   FOREIGN KEY (color_id)   REFERENCES colors (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- ready-made designs

-- A design in the shop's own catalogue. It has no stock: it is printed when
-- someone orders it. `surcharge_cents` is what it adds to the garment.
CREATE TABLE designs (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug             VARCHAR(120) NOT NULL,
  name             VARCHAR(160) NOT NULL,
  summary          VARCHAR(255) NULL,
  print_path       VARCHAR(255) NOT NULL,
  thumb_path       VARCHAR(255) NULL,
  surcharge_cents  INT UNSIGNED NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  sort_order       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY designs_slug (slug),
  INDEX designs_listing (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which ready-made designs may be printed on which garment.
CREATE TABLE design_products (
  design_id   INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (design_id, product_id),
  INDEX design_products_product (product_id),
  CONSTRAINT design_products_design  FOREIGN KEY (design_id)  REFERENCES designs (id)  ON DELETE CASCADE,
  CONSTRAINT design_products_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE design_layers (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  design_id   INT UNSIGNED NOT NULL,
  image_path  VARCHAR(255) NOT NULL,
  side        VARCHAR(10) NOT NULL DEFAULT 'front',
  position_x  TINYINT UNSIGNED NOT NULL DEFAULT 50,
  position_y  TINYINT UNSIGNED NOT NULL DEFAULT 50,
  scale_pct   SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  INDEX design_layers_design (design_id, sort_order),
  CONSTRAINT design_layers_design_fk FOREIGN KEY (design_id) REFERENCES designs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- customer artwork

-- One shape for two doors. The 3D editor writes `design_json` and a rendered
-- preview; the direct upload page writes the same row with `source='upload'`
-- and no editor state. Everything downstream -- pricing, the print queue, the
-- production sheet -- reads this table and never asks which door it came from.
CREATE TABLE custom_designs (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token          CHAR(32) NOT NULL,
  source         ENUM('editor','upload') NOT NULL DEFAULT 'editor',
  image_path     VARCHAR(255) NOT NULL,
  original_path  VARCHAR(255) NULL,
  -- The back's press file when an editor design differs front and back; NULL
  -- means original_path is the only file, printed wherever placement says.
  back_original_path VARCHAR(255) NULL,
  mime           VARCHAR(60) NOT NULL,
  width_px       INT UNSIGNED NOT NULL,
  height_px      INT UNSIGNED NOT NULL,
  dpi            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  quality        ENUM('ok','warn','reject') NOT NULL DEFAULT 'ok',
  placement      VARCHAR(10) NOT NULL DEFAULT 'front',
  design_json    MEDIUMTEXT NULL,
  preview_path   VARCHAR(255) NULL,
  source_path    VARCHAR(255) NULL,
  note           VARCHAR(500) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY custom_designs_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------ customers and carts

CREATE TABLE customers (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(160) NOT NULL,
  email          VARCHAR(190) NOT NULL,
  phone          VARCHAR(40) NOT NULL,
  password_hash  VARCHAR(255) NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE addresses (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id  INT UNSIGNED NOT NULL,
  label        VARCHAR(60) NULL,
  city         VARCHAR(80) NOT NULL,
  line1        VARCHAR(255) NOT NULL,
  line2        VARCHAR(255) NULL,
  is_default   TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX addresses_customer (customer_id),
  CONSTRAINT addresses_customer_fk FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The cart is a row, not a session key: a guest's cart has to survive the walk
-- from the phone to the laptop, and has to be mergeable into an account.
CREATE TABLE carts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token        CHAR(32) NOT NULL,
  customer_id  INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY carts_token (token),
  INDEX carts_customer (customer_id),
  CONSTRAINT carts_customer_fk FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Three shapes, one table. `item_type` says which of the two design columns is
-- filled; a CHECK would say it better but MariaDB 10.2 ignores them, so the
-- rule is enforced in CartService and asserted by the test suite instead.
CREATE TABLE cart_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id           INT UNSIGNED NOT NULL,
  item_type         ENUM('blank','ready','custom') NOT NULL DEFAULT 'blank',
  variant_id        INT UNSIGNED NOT NULL,
  design_id         INT UNSIGNED NULL,
  custom_design_id  INT UNSIGNED NULL,
  placement         ENUM('front','back','both') NOT NULL DEFAULT 'front',
  quantity          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX cart_items_cart (cart_id),
  CONSTRAINT cart_items_cart_fk    FOREIGN KEY (cart_id)          REFERENCES carts (id) ON DELETE CASCADE,
  CONSTRAINT cart_items_variant_fk FOREIGN KEY (variant_id)       REFERENCES product_variants (id) ON DELETE CASCADE,
  CONSTRAINT cart_items_design_fk  FOREIGN KEY (design_id)        REFERENCES designs (id) ON DELETE CASCADE,
  CONSTRAINT cart_items_custom_fk  FOREIGN KEY (custom_design_id) REFERENCES custom_designs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------ orders

CREATE TABLE orders (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_number     VARCHAR(12) NOT NULL,
  customer_id      INT UNSIGNED NULL,
  customer_name    VARCHAR(160) NOT NULL,
  email            VARCHAR(190) NOT NULL,
  phone            VARCHAR(40) NOT NULL,
  phone_digits     VARCHAR(40) NOT NULL,
  city             VARCHAR(80) NOT NULL,
  address          TEXT NOT NULL,
  note             VARCHAR(500) NULL,
  subtotal_cents   INT UNSIGNED NOT NULL DEFAULT 0,
  discount_cents   INT UNSIGNED NOT NULL DEFAULT 0,
  shipping_cents   INT UNSIGNED NOT NULL DEFAULT 0,
  total_cents      INT UNSIGNED NOT NULL DEFAULT 0,
  payment_driver   VARCHAR(30) NOT NULL DEFAULT 'cod',
  payment_status   ENUM('unpaid','paid','refunded') NOT NULL DEFAULT 'unpaid',
  payment_ref      VARCHAR(120) NULL,
  status           ENUM('pending','paid','in_production','shipped','delivered','cancelled','refunded')
                     NOT NULL DEFAULT 'pending',
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY orders_number (order_number),
  INDEX orders_status (status, created_at),
  INDEX orders_customer (customer_id),
  INDEX orders_tracking (order_number, phone_digits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Everything the production floor needs is copied in, not joined. A product
-- renamed or a colour retired next month must not change what a printed order
-- said at the moment it was placed.
CREATE TABLE order_items (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          INT UNSIGNED NOT NULL,
  item_type         ENUM('blank','ready','custom') NOT NULL DEFAULT 'blank',
  variant_id        INT UNSIGNED NULL,
  product_id        INT UNSIGNED NULL,
  design_id         INT UNSIGNED NULL,
  custom_design_id  INT UNSIGNED NULL,
  sku               VARCHAR(40) NOT NULL,
  product_name      VARCHAR(160) NOT NULL,
  design_name       VARCHAR(160) NULL,
  color_name        VARCHAR(60) NOT NULL,
  color_hex         CHAR(7) NOT NULL,
  size_code         VARCHAR(10) NOT NULL,
  placement         ENUM('front','back','both') NOT NULL DEFAULT 'front',
  quantity          SMALLINT UNSIGNED NOT NULL,
  unit_price_cents  INT UNSIGNED NOT NULL,
  surcharge_cents   INT UNSIGNED NOT NULL DEFAULT 0,
  line_total_cents  INT UNSIGNED NOT NULL,
  INDEX order_items_order (order_id),
  INDEX order_items_custom (custom_design_id),
  INDEX order_items_variant (variant_id),
  INDEX order_items_product (product_id),
  CONSTRAINT order_items_order_fk  FOREIGN KEY (order_id)         REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT order_items_design_fk FOREIGN KEY (design_id)        REFERENCES designs (id) ON DELETE SET NULL,
  CONSTRAINT order_items_custom_fk FOREIGN KEY (custom_design_id) REFERENCES custom_designs (id) ON DELETE SET NULL,
  -- RESTRICT, not CASCADE: what has been sold cannot be deleted out from under
  -- the line that sold it. Deleting a product cascades to its variants, so this
  -- one key also stops a product with order history from being deleted at all.
  -- The application refuses first with a readable message; this is the floor.
  CONSTRAINT order_items_variant_fk FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT,
  CONSTRAINT order_items_product_fk FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One press file per artwork, not per garment. A team ordering ten shirts in
-- five sizes prints one transfer sheet design, and the sheet is the same file
-- whichever size it is pressed onto.
CREATE TABLE print_files (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  custom_design_id  INT UNSIGNED NULL,
  design_id         INT UNSIGNED NULL,
  -- 'single' is a design's only file; a design printed differently on each
  -- side has one 'front' and one 'back' row instead.
  side              ENUM('single','front','back') NOT NULL DEFAULT 'single',
  order_id          INT UNSIGNED NULL,
  driver            VARCHAR(20) NOT NULL,
  colour_space      VARCHAR(10) NOT NULL,
  pdf_path          VARCHAR(255) NOT NULL,
  preview_path      VARCHAR(255) NOT NULL,
  dpi               SMALLINT UNSIGNED NOT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY print_files_custom_side (custom_design_id, side),
  INDEX print_files_order (order_id),
  CONSTRAINT print_files_custom_fk FOREIGN KEY (custom_design_id) REFERENCES custom_designs (id) ON DELETE CASCADE,
  CONSTRAINT print_files_order_fk  FOREIGN KEY (order_id)         REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------------- queue

CREATE TABLE jobs (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type           VARCHAR(80) NOT NULL,
  payload        TEXT NOT NULL,
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  reserved_at    DATETIME NULL,
  reserved_token CHAR(32) NULL,
  available_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY jobs_token (reserved_token),
  INDEX jobs_ready (reserved_at, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE failed_jobs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id      INT UNSIGNED NOT NULL,
  type        VARCHAR(80) NOT NULL,
  payload     TEXT NOT NULL,
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error       TEXT NOT NULL,
  failed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- seed data

INSERT INTO hero_slides
  (model_path, poster_path, garment_color, background_value,
   tab_word, wordmark, tagline, stats, layout, cta_label, cta_url, sort_order, is_active)
VALUES
(
  '/models/tshirt.glb', '/images/hero-poster.webp', '#E7E4DC', '#141414',
  'قصّة واسعة', 'OVERSIZE',
  'من اختيار القماش إلى القص والخياطة والطباعة والتغليف — كل شيء في مكان واحد.',
  '[{"value":"٢٤٠ غم","label":"قطن ممشّط"},{"value":"٥٠ غسلة","label":"يبقى كما هو"}]',
  NULL, 'صمّم التيشيرت الخاص بك', '/design.php', 0, 1
),
(
  '/models/tshirt.glb', '/images/hero-poster.webp', '#1C1C1E', '#121110',
  'طباعة DTF', 'ORIGINAL',
  'حبر يتغلغل في النسيج بدل أن يجلس فوقه، فيبقى التصميم كما هو بعد خمسين غسلة.',
  '[{"value":"٣٠٠ DPI","label":"دقّة الطباعة"},{"value":"٢٨×٣٥","label":"مساحة الصدر سم"}]',
  NULL, 'صمّم التيشيرت الخاص بك', '/design.php', 1, 1
);

INSERT INTO colors (id, name, hex, sort_order) VALUES
  (1, 'أبيض عاجي', '#EFEDE7', 0),
  (2, 'أسود',      '#1C1C1E', 1),
  (3, 'أزرق ليلي', '#3A5A78', 2),
  (4, 'عنّابي',    '#8A2E2E', 3),
  (5, 'رملي',      '#E7E4DC', 4),
  (6, 'زيتوني',    '#4A5D3A', 5);

-- Measured flat across the chest and from the shoulder seam down.
INSERT INTO sizes (id, code, label, chest_cm, length_cm, sort_order) VALUES
  (1, 'S',   'صغير',        48.0, 68.0, 0),
  (2, 'M',   'متوسط',       51.0, 71.0, 1),
  (3, 'L',   'كبير',        54.0, 74.0, 2),
  (4, 'XL',  'كبير جدًا',    57.0, 76.0, 3),
  (5, '2XL', 'كبير مضاعف',  60.0, 78.0, 4);

-- The two cuts the shop starts with. Both draw on tshirt.glb: it is the only
-- mesh whose print area has been measured, so graphic-premium-cut is shown on
-- a stand-in until a second one is measured. See config/garment-models.php.
INSERT INTO garments (id, `key`, name_ar, name_en, model_key, print_area_w_cm, print_area_h_cm, sort_order)
VALUES
  (1, 'premium-basic-cut',   'قصّة أساسية فاخرة', 'Premium Basic Cut',   'premium-basic',   28.0, 35.0, 0),
  (2, 'graphic-premium-cut', 'قصّة فاخرة للطباعة', 'Graphic Premium Cut', 'graphic-premium', 28.0, 35.0, 1);

-- Both cuts offer the whole palette and the whole size chart until an owner
-- narrows them. Narrowing is one save; discovering a colour has vanished is a
-- support call.
INSERT INTO garment_colors (garment_id, color_id, sort_order)
SELECT g.id, c.id, c.sort_order FROM garments g CROSS JOIN colors c;

INSERT INTO garment_sizes (garment_id, size_id, sort_order)
SELECT g.id, s.id, s.sort_order FROM garments g CROSS JOIN sizes s;

INSERT INTO products
  (id, slug, name, summary, description, fabric, fit, care, print_method,
  garment, garment_id, base_price_cents, image_path, sort_order, sold_count)
VALUES
(
  1, 'classic-tee', 'تيشيرت كلاسيكي', 'قطن ممشّط ٢٤٠ غم، قصّة عادية',
  'تيشيرت قطن ممشّط بوزن ٢٤٠ غرامًا للمتر، خياطة مزدوجة عند الأكمام والذيل، ورقبة مضلّعة لا تتمدّد.',
  'قطن ممشّط ١٠٠٪، ٢٤٠ غم/م²', 'قصّة عادية',
  'غسيل مقلوب على ٣٠ درجة. لا تكوِ على الطباعة مباشرة. لا مبيّض.',
  'DTF', 'tshirt', 1, 1900, '/images/products/classic-tee.webp', 0, 42
),
(
  2, 'oversize-tee', 'تيشيرت واسع', 'قصّة واسعة بأكتاف منسدلة',
  'قصّة واسعة بأكتاف منسدلة وجسم أطول، من القماش نفسه بوزن ٢٤٠ غرامًا.',
  'قطن ممشّط ١٠٠٪، ٢٤٠ غم/م²', 'قصّة واسعة، أكتاف منسدلة',
  'غسيل مقلوب على ٣٠ درجة. لا تكوِ على الطباعة مباشرة. لا مبيّض.',
  'DTF', 'tshirt', 1, 2400, '/images/products/oversize-tee.webp', 1, 17
);

-- classic-tee: four colours by five sizes. oversize-tee: three by four.
INSERT INTO product_variants (product_id, color_id, size_id, sku, price_cents, stock)
SELECT p.id, c.id, s.id,
       CONCAT('TS-', p.id, '-', c.id, '-', s.code),
       p.base_price_cents,
       12
  FROM products p
  JOIN colors c ON (p.id = 1 AND c.id IN (1, 2, 3, 4)) OR (p.id = 2 AND c.id IN (5, 2, 6))
  JOIN sizes  s ON (p.id = 1) OR (p.id = 2 AND s.id >= 2);

-- The colours each product offers, mirroring the variants seeded above.
INSERT INTO product_colors (product_id, color_id, sort_order)
SELECT DISTINCT v.product_id, v.color_id, c.sort_order
  FROM product_variants v JOIN colors c ON c.id = v.color_id;

-- The basic cut can be designed on from the start; the graphic cut has no plain
-- product of its own yet, so it stays out of the editor until one is made.
UPDATE garments SET base_product_id = 1 WHERE `key` = 'premium-basic-cut';

INSERT INTO product_images (product_id, color_id, path, image_type, primary_for, width_px, height_px, sort_order)
VALUES
  (1, NULL, '/images/products/classic-tee.webp',  'product', 1, 1200, 1200, 0),
  (2, NULL, '/images/products/oversize-tee.webp', 'product', 2, 1200, 1200, 0);

INSERT INTO designs (id, slug, name, summary, print_path, thumb_path, surcharge_cents, sort_order)
VALUES
  (1, 'amman-skyline', 'أفق عمّان', 'خط أبيض على خلفية شفافة',
     '/uploads/designs/catalog/amman-skyline.png', '/images/designs/amman-skyline.webp', 400, 0),
  (2, 'kufic-salam',   'سلام كوفي',  'خط كوفي مربّع',
     '/uploads/designs/catalog/kufic-salam.png',   '/images/designs/kufic-salam.webp',   500, 1),
  (3, 'olive-branch',  'غصن زيتون',  'رسم بخط واحد',
     '/uploads/designs/catalog/olive-branch.png',  '/images/designs/olive-branch.webp',  400, 2);

-- Added here rather than with the table: garments is declared before products,
-- because a product points at a type.
ALTER TABLE garments
  ADD CONSTRAINT garments_base_product_fk
    FOREIGN KEY (base_product_id) REFERENCES products (id) ON DELETE SET NULL;

ALTER TABLE hero_slides
  ADD CONSTRAINT hero_slides_color_fk  FOREIGN KEY (color_id)  REFERENCES colors (id)  ON DELETE SET NULL,
  ADD CONSTRAINT hero_slides_design_fk FOREIGN KEY (design_id) REFERENCES designs (id) ON DELETE SET NULL;

INSERT INTO design_products (design_id, product_id) VALUES
  (1, 1), (1, 2), (2, 1), (3, 1), (3, 2);
