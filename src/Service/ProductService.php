<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductRepository;
use App\Support\Cache;
use App\Support\CacheInterface;
use App\Support\Config;
use App\Support\Image;

final class ProductService
{
    public const CACHE_KEY = 'catalog';
    public const TRENDING_KEY = 'home.trending';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly CacheInterface $cache,
    ) {
    }

    public static function make(): self
    {
        return new self(ProductRepository::make(), Cache::store());
    }

    /**
     * The listing, in two queries and not one more.
     *
     * The products come back in one statement and every variant belonging to
     * them in a second; the colours on a card are then grouped in PHP. Asking
     * the database for a product's colours inside the render loop is the way a
     * page that is quick with three products becomes unusable with thirty, and
     * nothing in the output would have looked any different until it did.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,sort:string,search:string}
     */
    public function catalog(string $sort = 'newest', string $search = ''): array
    {
        $sort   = in_array($sort, ProductRepository::SORTS, true) ? $sort : 'newest';
        $search = mb_substr(trim($search), 0, 80);

        // Only the unsearched views are worth caching; a search is typed once.
        $key      = self::CACHE_KEY . '.' . $sort;
        $producer = fn (): array => $this->buildCatalog($sort, $search);

        $items = $search === ''
            ? $this->cache->remember($key, $producer, (int) Config::get('shop.catalog_cache_ttl', 900))
            : $producer();

        return [
            'items'  => $items,
            'total'  => count($items),
            'sort'   => $sort,
            'search' => $search,
        ];
    }

    /**
     * One page of one cut: printed or plain.
     *
     * Not cached, and deliberately. catalog() caches because there are four
     * possible answers -- one per sort -- and every visitor gets one of them.
     * Here the answer varies by cut, sort, colour, size and page number, which
     * is a cache with hundreds of keys that each get one hit; the paging is in
     * SQL and the index covers it, so the query is cheap enough that caching it
     * would cost more than it saves.
     *
     * @param string $garmentKey a `garments`.`key`
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int,perPage:int,search:string}
     */
    public function typeCatalog(
        string $garmentKey,
        string $sort = 'newest',
        ?int $colorId = null,
        ?string $sizeCode = null,
        int $page = 1,
        int $perPage = 12,
        string $search = ''
    ): array {
        $sort    = in_array($sort, ProductRepository::SORTS, true) ? $sort : 'newest';
        $perPage = max(4, min(48, $perPage));
        $page    = max(1, $page);
        // The same ceiling catalog() puts on a search term, for the same reason:
        // past eighty characters it is not a search, it is a payload.
        $search  = mb_substr(trim($search), 0, 80);

        $result = $this->products->typeListing(
            $garmentKey,
            $sort,
            $colorId,
            $sizeCode,
            $perPage,
            ($page - 1) * $perPage,
            $search
        );

        $total = $result['total'];
        $pages = max(1, (int) ceil($total / $perPage));

        /*
         * Asked for page 9 of a 3-page result -- a stale bookmark, or a page
         * number typed into the URL. The last page is the honest answer: an
         * empty grid with "12 products" above it reads as a bug, and a 404 for
         * a shop that simply has fewer products than it did is worse.
         */
        if ($page > $pages && $total > 0) {
            $page   = $pages;
            $result = $this->products->typeListing(
                $garmentKey,
                $sort,
                $colorId,
                $sizeCode,
                $perPage,
                ($page - 1) * $perPage,
                $search
            );
        }

        return [
            'items'   => $this->hydrateAll($result['rows']),
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'perPage' => $perPage,
            'search'  => $search,
        ];
    }

    /**
     * The colours and sizes this cut can actually be bought in.
     *
     * @return array{colors:array<int,array<string,mixed>>,sizes:array<int,array<string,mixed>>}
     */
    public function refinementsFor(string $garmentKey): array
    {
        return $this->products->typeRefinements($garmentKey);
    }

    /**
     * What the owner marked as trending, for the home page.
     *
     * Cached, unlike typeCatalog(): there is one answer per limit and every
     * visitor to the landing page gets it.
     *
     * @return array<int,array<string,mixed>>
     */
    public function trending(int $limit = 8): array
    {
        $limit = max(1, min(24, $limit));

        return $this->cache->remember(
            self::TRENDING_KEY . '.' . $limit,
            fn (): array => $this->hydrateAll($this->products->trending($limit)),
            (int) Config::get('shop.catalog_cache_ttl', 900)
        );
    }

    /**
     * Rows into cards, with every variant fetched in one query rather than one
     * per row. The same bargain buildCatalog() makes, extracted so the two
     * listings cannot drift apart on it.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function hydrateAll(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids      = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $variants = $this->products->variantsFor($ids);

        $byProduct = [];

        foreach ($variants as $variant) {
            $byProduct[(int) $variant['product_id']][] = $variant;
        }

        $items = [];

        foreach ($rows as $row) {
            $items[] = $this->hydrate($row, $byProduct[(int) $row['id']] ?? []);
        }

        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function buildCatalog(string $sort, string $search): array
    {
        // query 1 for the products, and hydrateAll() takes one more for every
        // variant belonging to them. Two, whatever the size of the catalogue.
        return $this->hydrateAll($this->products->listing($sort, $search));
    }

    /** One product with everything its page needs. */
    public function bySlug(string $slug): ?array
    {
        $row = $this->products->findBySlug($slug);

        if ($row === null) {
            return null;
        }

        $product = $this->hydrate($row, $this->products->variantsFor([(int) $row['id']]));
        $images = array_map($this->hydrateImage(...), $this->products->imagesFor([(int) $row['id']]));
        usort($images, static fn (array $a, array $b): int => [!$a['is_primary'], $a['sort_order'], $a['id']]
            <=> [!$b['is_primary'], $b['sort_order'], $b['id']]);

        $product['sizes_chart'] = $this->products->sizes();
        $product['images']      = $images;
        $product['primary_image'] = $images[0] ?? null;

        /*
         * No ready-made design list any more.
         *
         * The shop's catalogue of designs used to be joined in here and rendered
         * as a picker on the product page: pick a shirt, then pick a print to go
         * on it, and the line became item_type='ready' with the design's
         * surcharge added. That is gone. A product carries its own artwork now,
         * or it is plain, and either way what the customer buys is the product.
         *
         * `designs`, `design_products` and `design_layers` are still read
         * elsewhere -- the cart and the order writer resolve them for lines that
         * were placed while the picker existed -- but nothing on the storefront
         * offers them any more.
         */

        return $product;
    }

    public function find(int $id): ?array
    {
        $row = $this->products->find($id);

        return $row === null ? null : $this->hydrate($row, $this->products->variantsFor([$id]));
    }

    /**
     * Folds a product's variants into the two lists a page actually renders --
     * the colours it comes in and the sizes it comes in -- plus a map from a
     * colour and size to the variant that is the thing being bought.
     *
     * @param array<int,array<string,mixed>> $variants
     */
    private function hydrate(array $row, array $variants): array
    {
        $row['id']               = (int) $row['id'];
        $row['base_price_cents'] = (int) $row['base_price_cents'];
        $row['is_active']        = (bool) $row['is_active'];
        $row['sold_count']       = (int) $row['sold_count'];

        $colors = [];
        $sizes  = [];
        $map    = [];
        $prices = [];
        $stock  = 0;

        foreach ($variants as $variant) {
            $colorId = (int) $variant['color_id'];
            $sizeId  = (int) $variant['size_id'];

            $colors[$colorId] ??= [
                'id'   => $colorId,
                'name' => $variant['color_name'],
                'hex'  => $variant['color_hex'],
            ];

            $sizes[$sizeId] ??= [
                'id'        => $sizeId,
                'code'      => $variant['size_code'],
                'label'     => $variant['size_label'],
                'chest_cm'  => (float) $variant['chest_cm'],
                'length_cm' => (float) $variant['length_cm'],
            ];

            $map[$colorId . ':' . $sizeId] = [
                'id'          => (int) $variant['id'],
                'sku'         => $variant['sku'],
                'price_cents' => (int) $variant['price_cents'],
                'stock'       => (int) $variant['stock'],
            ];

            $prices[] = (int) $variant['price_cents'];
            $stock   += (int) $variant['stock'];
        }

        /*
         * The colours and sizes offered are folded from the variants, which is
         * the buyable truth: product_colors says what the product is meant to
         * come in, a variant says what can actually be sold. A colour whose
         * every variant is switched off should disappear from the page, and
         * reading the variants is what makes that happen by itself.
         */
        $row['colors']    = array_values($colors);
        $row['sizes']     = array_values($sizes);
        $row['variants']  = $map;
        $row['in_stock']  = $stock > 0;

        /*
         * The artwork, when the product has one. Its presence is what makes the
         * product a printed one -- there is no type column to consult.
         */
        $artworkPath = (string) ($row['artwork_path'] ?? '');
        $row['artwork'] = $artworkPath === '' ? null : [
            'path'  => $artworkPath,
            'x'     => (int) ($row['artwork_x'] ?? 50),
            'y'     => (int) ($row['artwork_y'] ?? 50),
            'scale' => (int) ($row['artwork_scale'] ?? 100),
            'side'  => (string) ($row['artwork_side'] ?? 'front') === 'back' ? 'back' : 'front',
        ];
        $row['is_printed'] = $row['artwork'] !== null;
        $row['min_price'] = $prices === [] ? $row['base_price_cents'] : min($prices);
        $row['max_price'] = $prices === [] ? $row['base_price_cents'] : max($prices);
        $row['image']     = Image::responsive(
            (string) ($row['image_path'] ?? ''),
            '(max-width: 700px) 46vw, 23vw'
        );

        return $row;
    }

    private function hydrateImage(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['width_px'] = (int) $row['width_px'] > 0 ? (int) $row['width_px'] : 900;
        $row['height_px'] = (int) $row['height_px'] > 0 ? (int) $row['height_px'] : 900;
        $row['is_primary'] = (int) ($row['primary_for'] ?? 0) === (int) $row['product_id'];
        $row['responsive'] = Image::responsive(
            (string) $row['path'],
            '(max-width: 900px) 92vw, 46vw'
        );
        $thumbnail = Image::thumbnail((string) $row['path']);
        $row['thumbnail'] = is_file((string) Config::get('app.paths.public') . $thumbnail)
            ? $thumbnail
            : $row['responsive']['src'];

        return $row;
    }

    /**
     * Called by every admin save. The catalogue cache exists so a listing can
     * be served without touching the database at all; the price of that is
     * that a save has to say so, because nothing else will.
     */
    public function forgetCache(): void
    {
        foreach (ProductRepository::SORTS as $sort) {
            $this->cache->forget(self::CACHE_KEY . '.' . $sort);
        }

        /*
         * The trending strip too. It is keyed by limit because the home page
         * asks for eight and nothing stops a second caller asking for four, and
         * a save that cleared only one of them would leave the other showing a
         * product that has just been unticked. The range is the one trending()
         * clamps to, so the loop cannot miss a live key.
         */
        for ($limit = 1; $limit <= 24; $limit++) {
            $this->cache->forget(self::TRENDING_KEY . '.' . $limit);
        }
    }

    public function priceLabel(int $cents): string
    {
        return number_format($cents / 100, 2) . ' ' . Config::get('shop.currency', 'د.أ');
    }
}
