<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * The listing is deliberately two queries and not more: one for the page of
 * products, one for every variant belonging to that page. Fetching a product's
 * colours inside the render loop is the classic way a catalogue page that was
 * fast with three products becomes unusable with thirty, and it is invisible
 * until it happens.
 */
final class ProductRepository extends Repository
{
    public const SORTS = ['newest', 'bestselling', 'price_asc', 'price_desc'];

    /** @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int} */
    /**
     * Sort keys the admin list may ask for, and the columns they mean. Anything
     * else falls back to the most recently changed first.
     */
    private const ADMIN_SORTS = [
        'name'    => 'name',
        'price'   => 'base_price_cents',
        'updated' => 'updated_at',
    ];

    public function paginate(string $search, int $page, int $perPage = 20, string $sort = 'updated', string $dir = 'desc'): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $where = '';
        $params = [];

        if ($search !== '') {
            $where = ' WHERE name LIKE ? OR slug LIKE ?';
            $term = '%' . self::escapeLike($search) . '%';
            $params = [$term, $term];
        }

        $count = $this->pdo()->prepare('SELECT COUNT(*) FROM products' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $stmt = $this->pdo()->prepare(
            'SELECT * FROM products' . $where
            . ' ORDER BY ' . (self::ADMIN_SORTS[$sort] ?? 'updated_at') . ' ' . ($dir === 'asc' ? 'ASC' : 'DESC')
            . ', id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    public function create(array $values): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO products
                (name, slug, garment, garment_id, artwork_path, artwork_x, artwork_y,
                 artwork_scale, artwork_side, presentation_type, design_x, design_y, design_scale,
                 show_3d, customization_enabled, description, base_price_cents)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $values['name'],
            $values['slug'],
            $values['garment'],
            $values['garment_id'],
            $values['artwork_path'],
            $values['artwork_x'],
            $values['artwork_y'],
            $values['artwork_scale'],
            $values['artwork_side'],
            $values['presentation_type'],
            $values['design_x'],
            $values['design_y'],
            $values['design_scale'],
            $values['show_3d'],
            $values['customization_enabled'],
            $values['description'],
            $values['base_price_cents'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function update(int $id, array $values): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE products
                SET name = ?, slug = ?, garment = ?, garment_id = ?,
                    artwork_path = ?, artwork_x = ?, artwork_y = ?,
                    artwork_scale = ?, artwork_side = ?, presentation_type = ?,
                    design_x = ?, design_y = ?, design_scale = ?, show_3d = ?,
                    customization_enabled = ?, description = ?,
                    base_price_cents = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $values['name'],
            $values['slug'],
            $values['garment'],
            $values['garment_id'],
            $values['artwork_path'],
            $values['artwork_x'],
            $values['artwork_y'],
            $values['artwork_scale'],
            $values['artwork_side'],
            $values['presentation_type'],
            $values['design_x'],
            $values['design_y'],
            $values['design_scale'],
            $values['show_3d'],
            $values['customization_enabled'],
            $values['description'],
            $values['base_price_cents'],
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
    }

    /* ---------------------------------------------- the colours of a product */

    /**
     * The colours this product is offered in.
     *
     * A stored set rather than one derived from the active variants. The two
     * agree almost always, but not quite: a colour whose every variant an owner
     * switched off by hand is still a colour the product is meant to come in,
     * and deriving the set would quietly drop it at the next save.
     *
     * @return array<int,int>
     */
    public function colorIdsForProduct(int $productId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT color_id FROM product_colors WHERE product_id = ? ORDER BY sort_order ASC, color_id ASC'
        );
        $stmt->execute([$productId]);

        return array_map('intval', array_column($stmt->fetchAll(), 'color_id'));
    }

    /** @return array<int,array<string,mixed>> the colour rows themselves, in their order */
    public function colorsForProduct(int $productId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT c.* FROM product_colors pc
               JOIN colors c ON c.id = pc.color_id
              WHERE pc.product_id = ?
              ORDER BY pc.sort_order ASC, c.id ASC'
        );
        $stmt->execute([$productId]);

        return $stmt->fetchAll();
    }

    /** @param array<int,int> $colorIds */
    public function setColorsForProduct(int $productId, array $colorIds): void
    {
        $this->pdo()->prepare('DELETE FROM product_colors WHERE product_id = ?')->execute([$productId]);

        if ($colorIds === []) {
            return;
        }

        $stmt = $this->pdo()->prepare(
            'INSERT INTO product_colors (product_id, color_id, sort_order) VALUES (?, ?, ?)'
        );

        foreach (self::ints($colorIds) as $index => $colorId) {
            $stmt->execute([$productId, $colorId, $index]);
        }
    }

    public function slugExists(string $slug, int $exceptId = 0): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1 FROM products WHERE slug = ? AND id <> ? LIMIT 1');
        $stmt->execute([$slug, $exceptId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The whole active catalogue, sorted and searched in SQL.
     *
     * There is no LIMIT and no OFFSET. The catalogue is under twenty products
     * by decision, so paging it would cost a second statement -- a COUNT, or
     * the deprecated SQL_CALC_FOUND_ROWS and its FOUND_ROWS() round trip -- to
     * save nothing. Two statements serve the whole page: this one and the
     * variants. When the catalogue outgrows that, the LIMIT and its COUNT come
     * back together and the budget becomes three, deliberately.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listing(string $sort, string $search): array
    {
        $where  = 'WHERE p.is_active = 1';
        $params = [];

        if ($search !== '') {
            $where .= ' AND (p.name LIKE ? OR p.summary LIKE ? OR p.description LIKE ?)';
            $term   = '%' . self::escapeLike($search) . '%';
            $params = [$term, $term, $term];
        }

        $order = match ($sort) {
            'bestselling' => 'p.sold_count DESC, p.sort_order ASC, p.id ASC',
            'price_asc'   => 'p.base_price_cents ASC, p.id ASC',
            'price_desc'  => 'p.base_price_cents DESC, p.id ASC',
            default       => 'p.created_at DESC, p.id DESC',
        };

        /*
         * The garment type comes along on the same statement. It is a tiny
         * table joined on a primary key, and the alternative -- asking for a
         * product's type while rendering its card -- is the query-per-row habit
         * this method's own comment exists to warn about.
         */
        $stmt = $this->pdo()->prepare(
            'SELECT p.*, g.name_ar AS garment_name, g.`key` AS garment_key
               FROM products p
          LEFT JOIN garments g ON g.id = p.garment_id '
            . $where . ' ORDER BY ' . $order
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * One page of one cut, narrowed to a colour and a size if asked.
     *
     * WHY THIS IS NOT listing() WITH MORE ARGUMENTS
     *
     * listing() returns the whole catalogue in one statement and the service
     * caches it whole, which is right for a page that shows everything. This
     * one is paged in SQL, so it cannot be cached the same way and must not
     * pretend to be: the caller asks for rows 40 to 59 of the printed cut in
     * black, and the answer is a different set for every combination. Folding
     * the two into one method would give listing()'s callers a LIMIT they do
     * not want and this one a cache that would hold thousands of keys.
     *
     * The narrowing is two EXISTS subqueries and not two JOINs. A join to
     * product_variants multiplies the product row by its variants -- a shirt in
     * six colours and five sizes comes back thirty times -- and the DISTINCT
     * that repairs that also breaks the LIMIT, because it is applied after the
     * rows are counted. EXISTS asks the question that is actually being asked:
     * is there a sellable variant of this product in that colour.
     *
     * The search is a LIKE on the name and the summary, and it is scoped to the
     * cut like everything else here: someone searching inside the printed
     * catalogue is asking about printed shirts, and returning a plain one because
     * it happens to match the word would be answering a question they did not ask.
     * The description is deliberately NOT searched -- it is a paragraph, so
     * matching it returns products whose connection to the term the shopper cannot
     * see anywhere on the card.
     *
     * @param string  $garmentKey  a `garments`.`key`; no row means an empty page
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function typeListing(
        string $garmentKey,
        string $sort,
        ?int $colorId,
        ?string $sizeCode,
        int $limit,
        int $offset,
        string $search = ''
    ): array {
        $where  = 'WHERE p.is_active = 1 AND g.`key` = ?';
        $params = [$garmentKey];

        if ($search !== '') {
            $where .= ' AND (p.name LIKE ? OR p.summary LIKE ?)';
            $term   = '%' . self::escapeLike($search) . '%';
            $params[] = $term;
            $params[] = $term;
        }

        if ($colorId !== null) {
            $where .= ' AND EXISTS (SELECT 1 FROM product_variants v
                                     WHERE v.product_id = p.id AND v.is_active = 1 AND v.color_id = ?)';
            $params[] = $colorId;
        }

        if ($sizeCode !== null) {
            $where .= ' AND EXISTS (SELECT 1 FROM product_variants v
                                      JOIN sizes s ON s.id = v.size_id
                                     WHERE v.product_id = p.id AND v.is_active = 1 AND s.code = ?)';
            $params[] = $sizeCode;
        }

        /*
         * INNER JOIN here, where listing() uses LEFT: a product with no type at
         * all belongs to neither cut, and a LEFT join with `g`.`key` = ? in the
         * WHERE clause is an inner join written misleadingly.
         */
        $from = ' FROM products p JOIN garments g ON g.id = p.garment_id ' . $where;

        $count = $this->pdo()->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        if ($total === 0) {
            return ['rows' => [], 'total' => 0];
        }

        $order = match ($sort) {
            'bestselling' => 'p.sold_count DESC, p.sort_order ASC, p.id ASC',
            'price_asc'   => 'p.base_price_cents ASC, p.id ASC',
            'price_desc'  => 'p.base_price_cents DESC, p.id ASC',
            default       => 'p.created_at DESC, p.id DESC',
        };

        /*
         * LIMIT and OFFSET are interpolated, not bound. They have to be: with
         * PDO::ATTR_EMULATE_PREPARES off, MySQL rejects a placeholder in LIMIT.
         * Both are cast to int by the caller and clamped below, so there is no
         * string here to inject through.
         */
        $limit  = max(1, min(60, $limit));
        $offset = max(0, $offset);

        $stmt = $this->pdo()->prepare(
            'SELECT p.*, g.name_ar AS garment_name, g.`key` AS garment_key'
            . $from . ' ORDER BY ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * The products the owner marked as trending, in the order they chose.
     *
     * trending_order first, newest as the tie-break, so a shop that ticks boxes
     * and never touches the order numbers still gets a sensible sequence.
     *
     * @return array<int,array<string,mixed>>
     */
    public function trending(int $limit): array
    {
        $limit = max(1, min(24, $limit));

        $stmt = $this->pdo()->prepare(
            'SELECT p.*, g.name_ar AS garment_name, g.`key` AS garment_key
               FROM products p
          LEFT JOIN garments g ON g.id = p.garment_id
              WHERE p.is_active = 1 AND p.is_trending = 1
              ORDER BY p.trending_order ASC, p.created_at DESC, p.id DESC
              LIMIT ' . $limit
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * The colours and sizes one cut is actually sellable in.
     *
     * The narrowing controls offer these and nothing else. A palette of every
     * colour in the shop would offer a shopper combinations that return an
     * empty page, which is the one thing a control like this must never do.
     *
     * @return array{colors:array<int,array<string,mixed>>,sizes:array<int,array<string,mixed>>}
     */
    public function typeRefinements(string $garmentKey): array
    {
        $colors = $this->pdo()->prepare(
            'SELECT DISTINCT c.id, c.name, c.hex, c.sort_order
               FROM colors c
               JOIN product_variants v ON v.color_id = c.id AND v.is_active = 1
               JOIN products p ON p.id = v.product_id AND p.is_active = 1
               JOIN garments g ON g.id = p.garment_id
              WHERE g.`key` = ?
              ORDER BY c.sort_order ASC, c.id ASC'
        );
        $colors->execute([$garmentKey]);

        $sizes = $this->pdo()->prepare(
            'SELECT DISTINCT s.id, s.code, s.label, s.sort_order
               FROM sizes s
               JOIN product_variants v ON v.size_id = s.id AND v.is_active = 1
               JOIN products p ON p.id = v.product_id AND p.is_active = 1
               JOIN garments g ON g.id = p.garment_id
              WHERE g.`key` = ?
              ORDER BY s.sort_order ASC, s.id ASC'
        );
        $sizes->execute([$garmentKey]);

        return ['colors' => $colors->fetchAll(), 'sizes' => $sizes->fetchAll()];
    }

    /** A customer searching for "50%" is searching for a string, not a wildcard. */
    private static function escapeLike(string $term): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $term
        );
    }

    /**
     * Every buyable variant of the given products, with the colour and size
     * spelled out. One query for a whole page of cards.
     *
     * @param array<int,int> $productIds
     * @return array<int,array<string,mixed>>
     */
    public function variantsFor(array $productIds): array
    {
        $ids = self::ints($productIds);

        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            'SELECT v.id, v.product_id, v.sku, v.price_cents, v.stock, v.is_active,
                    c.id AS color_id, c.name AS color_name, c.hex AS color_hex, c.sort_order AS color_order,
                    s.id AS size_id, s.code AS size_code, s.label AS size_label,
                    s.chest_cm, s.length_cm, s.sort_order AS size_order
               FROM product_variants v
               JOIN colors c ON c.id = v.color_id
               JOIN sizes  s ON s.id = v.size_id
              WHERE v.product_id IN (' . self::placeholders(count($ids)) . ') AND v.is_active = 1
              ORDER BY c.sort_order ASC, s.sort_order ASC'
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT p.*, g.name_ar AS garment_name, g.`key` AS garment_key
               FROM products p
          LEFT JOIN garments g ON g.id = p.garment_id
              WHERE p.slug = ? AND p.is_active = 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT p.*, g.name_ar AS garment_name, g.`key` AS garment_key
               FROM products p
          LEFT JOIN garments g ON g.id = p.garment_id
              WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int,int> $ids
     * @return array<int,array<string,mixed>> keyed by id
     */
    public function findMany(array $ids): array
    {
        $ids = self::ints($ids);

        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            'SELECT * FROM products WHERE id IN (' . self::placeholders(count($ids)) . ')'
        );
        $stmt->execute($ids);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function imagesFor(array $productIds): array
    {
        $ids = self::ints($productIds);

        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            'SELECT * FROM product_images
              WHERE product_id IN (' . self::placeholders(count($ids)) . ')
              ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> the whole size chart, in order */
    public function sizes(): array
    {
        return $this->pdo()->query('SELECT * FROM sizes ORDER BY sort_order ASC')->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function colors(): array
    {
        return $this->pdo()->query('SELECT * FROM colors ORDER BY sort_order ASC')->fetchAll();
    }

    /**
     * One variant with everything a cart line needs to describe itself, so the
     * cart never has to join back to products, colours and sizes separately.
     */
    public function variant(int $variantId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT v.*, p.name AS product_name, p.slug AS product_slug, p.image_path,
                    p.garment, p.is_active AS product_active,
                    c.name AS color_name, c.hex AS color_hex,
                    s.code AS size_code, s.label AS size_label
               FROM product_variants v
               JOIN products p ON p.id = v.product_id
               JOIN colors   c ON c.id = v.color_id
               JOIN sizes    s ON s.id = v.size_id
              WHERE v.id = ?'
        );
        $stmt->execute([$variantId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<int,int> $variantIds
     * @return array<int,array<string,mixed>> keyed by variant id
     */
    public function variants(array $variantIds): array
    {
        $ids = self::ints($variantIds);

        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo()->prepare(
            'SELECT v.*, p.name AS product_name, p.slug AS product_slug, p.image_path,
                    p.garment, p.is_active AS product_active,
                    c.name AS color_name, c.hex AS color_hex,
                    s.code AS size_code, s.label AS size_label
               FROM product_variants v
               JOIN products p ON p.id = v.product_id
               JOIN colors   c ON c.id = v.color_id
               JOIN sizes    s ON s.id = v.size_id
              WHERE v.id IN (' . self::placeholders(count($ids)) . ')'
        );
        $stmt->execute($ids);

        $rows = [];

        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /**
     * Every variant of a product, active or not, with the names the editor
     * shows. Ordered the way the two reference tables order themselves.
     *
     * @return array<int,array<string,mixed>>
     */
    public function variantsForEditor(int $productId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT v.id, v.color_id, v.size_id, v.sku, v.price_cents, v.stock,
                    v.is_active, v.deactivated_by_product,
                    c.name AS color_name, c.hex AS color_hex,
                    s.code AS size_code, s.label AS size_label
               FROM product_variants v
               JOIN colors c ON c.id = v.color_id
               JOIN sizes  s ON s.id = v.size_id
              WHERE v.product_id = ?
              ORDER BY c.sort_order, s.sort_order'
        );
        $stmt->execute([$productId]);

        return $stmt->fetchAll();
    }

    /**
     * The pairs a product already has, active or not, keyed "colorId-sizeId".
     *
     * Reconciliation needs to know that a row EXISTS before it knows anything
     * else about it, and the unique key on (product_id, color_id, size_id) is
     * what makes that a lookup rather than a scan.
     *
     * @return array<string,array{id:int,is_active:int}>
     */
    public function variantIndex(int $productId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id, color_id, size_id, is_active FROM product_variants WHERE product_id = ?'
        );
        $stmt->execute([$productId]);
        $index = [];

        foreach ($stmt->fetchAll() as $row) {
            $index[$row['color_id'] . '-' . $row['size_id']] = [
                'id' => (int) $row['id'],
                'is_active' => (int) $row['is_active'],
            ];
        }

        return $index;
    }

    public function insertVariant(array $values): int
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO product_variants
                (product_id, color_id, size_id, sku, price_cents, stock, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            (int) $values['product_id'],
            (int) $values['color_id'],
            (int) $values['size_id'],
            (string) $values['sku'],
            (int) $values['price_cents'],
            (int) ($values['stock'] ?? 0),
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Switches one variant on or off and nothing else.
     *
     * Price and stock are never named here: they belong to whoever typed them
     * into the catalogue page, and a product save has no business rewriting
     * them. `deactivated_by_product` records whether the product carried this
     * row down with it, so reactivating the product can raise the right ones.
     */
    public function setVariantActive(int $variantId, bool $active, bool $byProduct = false): void
    {
        $this->pdo()->prepare(
            'UPDATE product_variants SET is_active = ?, deactivated_by_product = ? WHERE id = ?'
        )->execute([$active ? 1 : 0, $active ? 0 : ($byProduct ? 1 : 0), $variantId]);
    }

    public function skuExists(string $sku): bool
    {
        $stmt = $this->pdo()->prepare('SELECT 1 FROM product_variants WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);

        return $stmt->fetchColumn() !== false;
    }

    public function findVariantBy(int $productId, int $colorId, int $sizeId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT id FROM product_variants
              WHERE product_id = ? AND color_id = ? AND size_id = ? AND is_active = 1'
        );
        $stmt->execute([$productId, $colorId, $sizeId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->variant((int) $row['id']);
    }

    /**
     * Claims stock for a set of variants inside the caller's transaction.
     *
     * SELECT ... FOR UPDATE holds the rows until the transaction ends, so two
     * checkouts racing for the last shirt are serialised by the database rather
     * than by luck. Ordered by id because two transactions taking the same rows
     * in different orders is how a deadlock is written.
     *
     * @param array<int,int> $wanted variant id => quantity
     * @return array<int,int> variant id => stock available, for those short
     */
    public function lockAndCheckStock(array $wanted): array
    {
        $ids = self::ints(array_keys($wanted));

        if ($ids === []) {
            return [];
        }

        sort($ids);

        $stmt = $this->pdo()->prepare(
            'SELECT id, stock FROM product_variants
              WHERE id IN (' . self::placeholders(count($ids)) . ')
              ORDER BY id ASC
              FOR UPDATE'
        );
        $stmt->execute($ids);

        $short = [];

        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];

            if ((int) $row['stock'] < (int) $wanted[$id]) {
                $short[$id] = (int) $row['stock'];
            }
        }

        return $short;
    }

    /** @param array<int,int> $taken variant id => quantity */
    public function decrementStock(array $taken): void
    {
        $stmt = $this->pdo()->prepare(
            'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );

        foreach ($taken as $variantId => $quantity) {
            $stmt->execute([$quantity, $variantId, $quantity]);
        }
    }

    /** @param array<int,int> $sold product id => quantity */
    public function recordSales(array $sold): void
    {
        $stmt = $this->pdo()->prepare('UPDATE products SET sold_count = sold_count + ? WHERE id = ?');

        foreach ($sold as $productId => $quantity) {
            $stmt->execute([$quantity, $productId]);
        }
    }
}
