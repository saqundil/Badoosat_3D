<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\DesignRepository;
use App\Repository\GarmentRepository;
use App\Repository\ProductRepository;
use App\Support\Config;
use App\Support\Db;
use App\Support\GarmentModels;
use App\Support\Image;
use App\Support\Price;
use App\Support\UploadException;
use App\Support\Uploader;
use App\Support\Validator;
use PDO;
use Throwable;

final class ProductManagementService
{
    /**
     * How many variants one product may generate.
     *
     * Six colours and five sizes is thirty, so this is nowhere near a working
     * limit -- it is a guard against a crafted request asking for the cross
     * product of everything, which would be a slow transaction and a table
     * nobody meant to create.
     */
    public const MAX_VARIANTS = 200;

    /** @var array<int,string>|null size id => code, read once per instance. */
    private ?array $sizeCodes = null;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly DesignRepository $designs,
        private readonly GarmentRepository $garmentTypes,
        private readonly ProductService $catalog,
        private readonly PDO $pdo,
    ) {
    }

    public static function make(): self
    {
        $pdo = Db::pdo();

        return new self(
            new ProductRepository($pdo),
            new DesignRepository($pdo),
            new GarmentRepository($pdo),
            ProductService::make(),
            $pdo
        );
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int,page:int,pages:int} */
    public function paginate(string $search, int $page, int $perPage = 20): array
    {
        return $this->products->paginate(mb_substr(trim($search), 0, 80), $page, $perPage);
    }

    public function find(int $id): ?array
    {
        return $this->products->find($id);
    }

    /** @return array<int,array<string,mixed>> */
    public function images(int $productId): array
    {
        return $productId > 0 ? $this->products->imagesFor([$productId]) : [];
    }

    /** @return array<string,array<string,mixed>> */
    /** @return array<int,array<string,mixed>> the palette, in its own order */
    public function colors(): array
    {
        return $this->products->colors();
    }

    /** @return array<int,array<string,mixed>> the size chart, in its own order */
    public function sizes(): array
    {
        return $this->products->sizes();
    }

    /** @return array<int,array<string,mixed>> every variant, active or not */
    public function variantRows(int $productId): array
    {
        return $productId > 0 ? $this->products->variantsForEditor($productId) : [];
    }

    /**
     * The print geometry file.
     *
     * Still config, still programmer data: UV rectangles measured against a
     * mesh. A product no longer chooses from this -- it chooses a garment TYPE,
     * and the type names a model whose registry entry names the geometry entry
     * here. Kept because Garment::make() and the editor read it by key.
     */
    public function garments(): array
    {
        return Config::file('garments');
    }

    /** @return array<int,array<string,mixed>> the types a product may be built on */
    public function garmentTypes(): array
    {
        return $this->garmentTypes->active();
    }

    public function garmentType(int $id): ?array
    {
        return $id > 0 ? $this->garmentTypes->find($id) : null;
    }

    /** @return array<int,array<string,mixed>> the colours this product's type is cut in */
    public function colorsForGarment(int $garmentId): array
    {
        return $garmentId > 0 ? $this->garmentTypes->colorsFor($garmentId) : [];
    }

    /** @return array<int,array<string,mixed>> the sizes this product's type is cut in */
    public function sizesForGarment(int $garmentId): array
    {
        return $garmentId > 0 ? $this->garmentTypes->sizesFor($garmentId) : [];
    }

    /**
     * The colours this product is offered in.
     *
     * Read from product_colors, which is the stored answer, rather than
     * inferred from the active variants. See the note on the repository method.
     *
     * @return array<int,int>
     */
    public function selectedColors(int $productId): array
    {
        return $productId > 0 ? $this->products->colorIdsForProduct($productId) : [];
    }

    public function designForProduct(int $productId): ?array
    {
        if ($productId < 1) {
            return null;
        }

        $ids = $this->designs->idsForProduct($productId);

        if ($ids === []) {
            return null;
        }

        $design = $this->designs->findMany([$ids[0]])[$ids[0]] ?? null;

        if ($design !== null) {
            $design['layers'] = $this->designs->layersForDesign((int) $design['id']);
        }

        return $design;
    }

    public function blank(): array
    {
        $types = $this->garmentTypes();

        return [
            'id' => 0,
            'name' => '',
            'slug' => '',
            'garment_id' => (int) ($types[0]['id'] ?? 0),
            'garment' => array_key_first($this->garments()) ?? 'tshirt',
            /* No artwork is the default, and its absence is what makes the
               product a plain piece. There is no second field saying so. */
            'artwork_path' => null,
            'artwork_x' => 50,
            'artwork_y' => 50,
            'artwork_scale' => 100,
            'artwork_side' => 'front',
            'presentation_type' => 'standard',
            'design_x' => 50,
            'design_y' => 50,
            'design_scale' => 100,
            'show_3d' => true,
            'customization_enabled' => true,
            'description' => '',
            'base_price_cents' => 0,
            'image_path' => null,
        ];
    }

    /** @throws ValidationException */
    public function save(array $input, array $files = []): int
    {
        $input += ['artwork_x' => 50, 'artwork_y' => 50, 'artwork_scale' => 100];
        $id = max(0, (int) ($input['id'] ?? 0));
        $errors = $this->validate($input, $id);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /*
         * The artwork.
         *
         * One file, placed on the model, stored on the product row. This
         * replaces the designs / design_products / design_layers triangle that
         * used to be written from here: a product with a print is a product
         * with a print, not a product joined to a catalogue design that only
         * ever had one member. Those three tables still hold live order
         * history, so they are still READ -- nothing writes to them any more.
         */
        $artworkFiles = $this->uploadedFiles((array) ($files['artwork'] ?? []));
        $existingArtwork = $id > 0 ? (string) ($this->find($id)['artwork_path'] ?? '') : '';
        $removeArtwork = !empty($input['remove_artwork']);

        $primaryImageId = max(0, (int) ($input['primary_image_id'] ?? 0));
        if ($primaryImageId > 0 && !$this->imageBelongsTo($primaryImageId, $id)) {
            throw new ValidationException(['primary_image_id' => 'الصورة الأساسية لا تتبع هذا المنتج.']);
        }

        $stored = [];
        $artworkUploads = [];
        $deleteAfterCommit = [];

        try {
            foreach ($this->uploadedFiles($files['product_images'] ?? []) as $file) {
                $upload = Uploader::make()->store($file, 'products');
                $stored[] = $upload + ['generated' => []];
                $stored[array_key_last($stored)]['generated'] = Image::generate($upload['path']);
            }

            foreach ($artworkFiles as $file) {
                $artworkUploads[] = Uploader::make()->store($file, 'artwork');
            }
        } catch (UploadException $error) {
            $this->deleteStored($stored);
            $this->deleteDesignUploads($artworkUploads);
            $field = $artworkFiles !== [] ? 'artwork' : 'product_images';
            throw new ValidationException([$field => $error->getMessage()]);
        } catch (Throwable $error) {
            $this->deleteStored($stored);
            $this->deleteDesignUploads($artworkUploads);
            throw $error;
        }

        /*
         * Which artwork this product ends up with: a newly uploaded file, or
         * the one it already had, or none because the box was ticked. Settled
         * before normalise() so the row is written once with the final answer.
         */
        $artworkPath = $artworkUploads[0]['path'] ?? ($removeArtwork ? null : ($existingArtwork ?: null));

        if ($artworkUploads !== [] && $existingArtwork !== '') {
            /* The replaced file goes only after the transaction commits. */
            $deleteAfterCommit[] = $existingArtwork;
        } elseif ($removeArtwork && $existingArtwork !== '') {
            $deleteAfterCommit[] = $existingArtwork;
        }

        $input['artwork_path'] = $artworkPath;
        $values = $this->normalise($input);

        try {
            $this->pdo->beginTransaction();

            if ($id > 0) {
                $this->products->update($id, $values);
            } else {
                $id = $this->products->create($values);
            }

            /*
             * Inside the same transaction as the product row, its colours, its
             * variants and its images: a product whose variants failed to write
             * is not a half-saved product, it is a rolled-back one.
             *
             * Sizes are NOT read from the request. A cut is made in the sizes
             * it is made in, so they come from the type -- which also means a
             * crafted request cannot add one the type does not have.
             */
            $colorIds = self::idList($input['colors'] ?? []);
            $sizeIds = $this->garmentTypes->sizeIdsFor((int) $values['garment_id']);

            $this->products->setColorsForProduct($id, $colorIds);

            $this->reconcileVariants(
                $id,
                $colorIds,
                $sizeIds,
                (int) $values['base_price_cents'],
                (string) $values['slug']
            );

            $this->updateImages($id, $input);
            $deleteAfterCommit = array_merge(
                $deleteAfterCommit,
                $this->deleteImages($id, array_keys((array) ($input['delete_image'] ?? [])))
            );
            $newImageIds = $this->insertImages($id, $stored);

            if ($deleteAfterCommit !== [] && $this->firstImageId($id) < 1) {
                throw new ValidationException(['product_images' => 'لا يمكن حذف الصورة الوحيدة للمنتج. ارفع بديلًا أولًا.']);
            }

            if ($primaryImageId < 1 || in_array($primaryImageId, array_map('intval', array_keys((array) ($input['delete_image'] ?? []))), true)) {
                $primaryImageId = $this->currentPrimaryImageId($id);
            }

            if ($primaryImageId < 1) {
                $primaryImageId = $newImageIds[0] ?? $this->firstImageId($id);
            }

            $this->setPrimaryImage($id, $primaryImageId);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->deleteStored($stored);
            $this->deleteDesignUploads($artworkUploads);
            throw $error;
        }

        $this->deletePaths($deleteAfterCommit);

        $this->catalog->forgetCache();

        return $id;
    }

    /**
     * How many order lines depend on this product.
     *
     * Both columns, because a line records the variant it was sold as AND the
     * product it came from, and a line old enough to predate one of them still
     * counts as history worth keeping.
     */
    public function orderLineCount(int $id): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM order_items
              WHERE product_id = ?
                 OR variant_id IN (SELECT id FROM product_variants WHERE product_id = ?)'
        );
        $stmt->execute([$id, $id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Takes a product off the shop without touching what has been sold.
     *
     * The variants go with it: the catalogue filters on the product's flag, but
     * a direct variant lookup -- the one an add-to-cart makes -- filters on the
     * variant's, so leaving those active would keep a retired product buyable
     * by anyone holding the URL.
     */
    public function setActive(int $id, bool $active): void
    {
        if ($id < 1) {
            return;
        }

        $this->pdo->prepare('UPDATE products SET is_active = ? WHERE id = ?')
            ->execute([$active ? 1 : 0, $id]);

        /*
         * Only the rows this switch is responsible for.
         *
         * A shop owner may have turned one colour off because the cloth ran
         * out. Taking the whole product off the shop for a season and putting
         * it back must not quietly put that colour on sale again -- the first
         * order for it could not be filled. So deactivating marks the rows it
         * carried down, and reactivating raises only the marked ones; a variant
         * switched off by hand carries no mark and stays exactly where it was.
         */
        if ($active) {
            $this->pdo->prepare(
                'UPDATE product_variants
                    SET is_active = 1, deactivated_by_product = 0
                  WHERE product_id = ? AND deactivated_by_product = 1'
            )->execute([$id]);
        } else {
            $this->pdo->prepare(
                'UPDATE product_variants
                    SET is_active = 0, deactivated_by_product = 1
                  WHERE product_id = ? AND is_active = 1'
            )->execute([$id]);
        }

        $this->catalog->forgetCache();
    }

    /**
     * @throws ValidationException when order history depends on this product
     */
    public function delete(int $id): void
    {
        if ($id < 1) {
            return;
        }

        /*
         * A sold product is not a mistake to be swept up, it is a record. The
         * line keeps the name, the colour and the size it was bought under, so
         * deleting the product does not visibly break an old order -- it
         * quietly severs it from the catalogue, takes every variant with it,
         * and empties the carts that were holding one. Refused, with the count,
         * and with the thing to do instead.
         */
        $lines = $this->orderLineCount($id);

        if ($lines > 0) {
            throw new ValidationException(['delete' =>
                'لا يمكن حذف هذا المنتج: يظهر في ' . $lines . ' سطر طلب. '
                . 'عطّله بدلًا من ذلك ليختفي من المتجر ويبقى سجلّ الطلبات سليمًا.']);
        }

        $paths = array_column($this->images($id), 'path');
        /* Designs that belong to this product ALONE, with their layers and
           files; one shared with another product is not ours to remove. */
        $designPaths = [];

        try {
            $this->pdo->beginTransaction();

            foreach ($this->soleDesignIds($id) as $designId) {
                $designPaths = array_merge($designPaths, $this->designPaths($designId));
                $this->designs->delete($designId);
            }

            $this->products->delete($id);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }

        $this->deletePaths(array_merge($paths, $designPaths));

        $this->catalog->forgetCache();
    }

    /**
     * Designs linked to this product and to no other.
     *
     * @return array<int,int>
     */
    private function soleDesignIds(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dp.design_id
               FROM design_products dp
              WHERE dp.product_id = ?
                AND NOT EXISTS (
                    SELECT 1 FROM design_products other
                     WHERE other.design_id = dp.design_id AND other.product_id <> dp.product_id
                )'
        );
        $stmt->execute([$productId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Every file a design owns: its layers, and the print and thumbnail it
     * names. Deduplicated, because the first layer is usually all three.
     *
     * @return array<int,string>
     */
    private function designPaths(int $designId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT image_path FROM design_layers WHERE design_id = ?'
        );
        $stmt->execute([$designId]);
        $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $row = $this->pdo->prepare('SELECT print_path, thumb_path FROM designs WHERE id = ?');
        $row->execute([$designId]);

        foreach ((array) ($row->fetch(PDO::FETCH_ASSOC) ?: []) as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        /* Only what this project wrote. A seeded path under /images belongs to
           the repository, not to the upload directory. */
        return array_values(array_unique(array_filter(
            $paths,
            static fn (mixed $path): bool => is_string($path) && str_starts_with($path, '/uploads/')
        )));
    }

    /** @return array<string,string> */
    private function validate(array $input, int $id): array
    {
        $validator = new Validator($input);
        $validator->required('name', 'الاسم')->maxLength('name', 160, 'الاسم');
        $validator->required('slug', 'المُعرّف')->maxLength('slug', 120, 'المُعرّف');
        /*
         * The type is a row, not a config key. It decides the model, the
         * colours and the sizes, so a product pointing at one that does not
         * exist -- or at one the owner has retired -- cannot be published.
         */
        $garmentId = (int) ($input['garment_id'] ?? 0);
        $type = $garmentId > 0 ? $this->garmentTypes->find($garmentId) : null;

        if ($type === null) {
            $validator->fail('garment_id', 'اختر نوع القطعة.');
        } elseif ((int) $type['is_active'] !== 1) {
            $validator->fail('garment_id', 'هذا النوع معطَّل. فعّله أو اختر نوعًا آخر.');
        }
        /*
         * A floor of one piastre, not zero. A product saved at zero reaches the
         * catalogue as "0 د.أ", which reads as free rather than as unpriced --
         * and the storefront now refuses to quote or sell it at all, so saving
         * one would be publishing a product nobody can buy.
         */
        $validator->decimal('base_price', Price::MIN, Price::MAX, 'السعر الأساسي');

        /* Where the artwork sits on the garment, as a percentage of the print
           area, and how big. The same ranges the live preview allows. */
        $validator->integer('artwork_x', 0, 100, 'موضع العمل الفني الأفقي');
        $validator->integer('artwork_y', 0, 100, 'موضع العمل الفني العمودي');
        $validator->integer('artwork_scale', 10, 150, 'حجم العمل الفني');
        $validator->in('artwork_side', ['front', 'back'], 'وجه الطباعة');

        $this->validateOptions($input, $validator, $type);

        $slug = $validator->string('slug');

        if ($slug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $validator->fail('slug', 'المُعرّف يقبل أحرفًا إنجليزية صغيرة وأرقامًا وشرطات فقط.');
        } elseif ($slug !== '' && $this->products->slugExists($slug, $id)) {
            $validator->fail('slug', 'المُعرّف مستخدم لمنتج آخر.');
        }

        return $validator->errors();
    }

    /**
     * The colours the form offered, checked against the product's TYPE.
     *
     * Two questions, not one. Is this a colour at all -- the palette answers
     * that. And is it a colour this cut is made in -- only the type answers
     * that, and it is the question that matters: the sewing floor cuts the
     * premium basic in six colours and nothing the product screen does can
     * conjure a seventh. A crafted request naming a real colour the type does
     * not carry must be refused as firmly as one naming colour 900.
     *
     * Sizes are not here. They are not the product's to choose: they come from
     * the type in save(), so there is no request value to validate.
     *
     * Choosing no colour is allowed. A product with no variants is not an
     * error, it is a product that is not finished -- the editor says so plainly
     * and the storefront already refuses to sell it.
     */
    private function validateOptions(array $input, Validator $validator, ?array $type): void
    {
        $colorIds = self::idList($input['colors'] ?? []);

        if ($colorIds === []) {
            return;
        }

        $knownColors = array_map('intval', array_column($this->products->colors(), 'id'));
        $unknownColors = array_diff($colorIds, $knownColors);

        if ($unknownColors !== []) {
            $validator->fail('colors',
                'لون غير معروف: ' . implode('، ', $unknownColors) . '. اختر من قائمة الألوان.');

            return;
        }

        if ($type === null) {
            return;
        }

        $garmentId = (int) $type['id'];
        $allowed = $this->garmentTypes->colorIdsFor($garmentId);
        $outside = array_values(array_diff($colorIds, $allowed));

        if ($outside !== []) {
            $names = array_column(
                array_filter(
                    $this->products->colors(),
                    static fn (array $color): bool => in_array((int) $color['id'], $outside, true)
                ),
                'name'
            );

            $validator->fail('colors',
                'هذه الألوان ليست من ألوان نوع «' . $type['name_ar'] . '»: '
                . implode('، ', $names !== [] ? $names : array_map('strval', $outside))
                . '. أضفها إلى النوع أولًا، أو اختر من ألوانه.');
        }

        $sizeCount = count($this->garmentTypes->sizeIdsFor($garmentId));
        $total = count($colorIds) * $sizeCount;

        if ($total > self::MAX_VARIANTS) {
            $validator->fail('colors',
                count($colorIds) . ' لون × ' . $sizeCount . ' مقاس = ' . $total
                . ' متغيّر، والحدّ الأقصى ' . self::MAX_VARIANTS . '. قلّل الاختيار.');
        }
    }

    /**
     * Whole positive ids from whatever the form sent, deduplicated.
     *
     * @return array<int,int>
     */
    private static function idList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            $id = (int) $value;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Brings a product's variants in line with the colours and sizes chosen,
     * without ever deleting one.
     *
     * Deleting is not an option that exists here. Migration 006 put a RESTRICT
     * between order_items and product_variants, so removing a variant that has
     * been sold would fail the whole save -- and even where it succeeded it
     * would take a row of history with it. Switching off keeps the record and
     * takes the variant out of the shop, which is what is actually being asked.
     *
     * The rule that matters most is the one that issues no statement at all: a
     * pair that exists, is active, and is still wanted is left exactly as it
     * is. Its price and its stock were typed into the catalogue page by the
     * shop owner, and a product save has no business touching either.
     *
     * @param array<int,int> $colorIds
     * @param array<int,int> $sizeIds
     * @return array{created:int,reactivated:int,deactivated:int,kept:int}
     */
    private function reconcileVariants(
        int $productId,
        array $colorIds,
        array $sizeIds,
        int $priceCents,
        string $slug
    ): array {
        $existing = $this->products->variantIndex($productId);
        $wanted = [];

        foreach ($colorIds as $colorId) {
            foreach ($sizeIds as $sizeId) {
                $wanted[$colorId . '-' . $sizeId] = [$colorId, $sizeId];
            }
        }

        $summary = ['created' => 0, 'reactivated' => 0, 'deactivated' => 0, 'kept' => 0];

        foreach ($wanted as $key => [$colorId, $sizeId]) {
            $row = $existing[$key] ?? null;

            if ($row === null) {
                $this->products->insertVariant([
                    'product_id' => $productId,
                    'color_id' => $colorId,
                    'size_id' => $sizeId,
                    'sku' => $this->skuFor($slug, $colorId, $sizeId),
                    'price_cents' => $priceCents,
                    'stock' => 0,
                ]);
                $summary['created']++;

                continue;
            }

            if ($row['is_active'] === 1) {
                $summary['kept']++;

                continue;
            }

            $this->products->setVariantActive($row['id'], true);
            $summary['reactivated']++;
        }

        foreach ($existing as $key => $row) {
            if (isset($wanted[$key]) || $row['is_active'] !== 1) {
                continue;
            }

            $this->products->setVariantActive($row['id'], false);
            $summary['deactivated']++;
        }

        return $summary;
    }

    /**
     * A stock keeping unit for a new variant.
     *
     * The slug leads, because that is what a person reading a packing slip
     * recognises. It is truncated because slugs run to 120 characters and the
     * column is 40. The colour is its id -- the palette has no code column --
     * and the size is its code, which is already short and already unique.
     *
     * The suffix loop is for the truncation: two products whose slugs share a
     * first sixteen characters would otherwise collide on the unique key and
     * fail the save.
     */
    private function skuFor(string $slug, int $colorId, int $sizeId): string
    {
        $sizeCode = (string) ($this->sizeCodes()[$sizeId] ?? $sizeId);
        $prefix = trim(strtoupper(substr((string) preg_replace('/[^a-z0-9]+/i', '-', $slug), 0, 16)), '-');
        $base = ($prefix === '' ? 'SKU' : $prefix) . '-' . $colorId . '-' . strtoupper($sizeCode);
        $sku = $base;
        $suffix = 1;

        while ($this->products->skuExists($sku)) {
            $suffix++;
            $sku = substr($base, 0, 36) . '-' . $suffix;
        }

        return $sku;
    }

    /** @return array<int,string> size id => code */
    private function sizeCodes(): array
    {
        return $this->sizeCodes ??= array_column($this->products->sizes(), 'code', 'id');
    }

    private function normalise(array $input): array
    {
        $description = trim((string) ($input['description'] ?? ''));
        $garmentId = (int) ($input['garment_id'] ?? 0);
        $type = $this->garmentTypes->find($garmentId);

        /*
         * The geometry key.
         *
         * products.garment still holds a key into config/garments.php, because
         * Garment::make() reads it and the customer's editor, the print service
         * and product.php all go through that. The type no longer stores it
         * directly: the type names a MODEL, and the registry says which
         * geometry that model was measured as. Two hops, so that a model and
         * its measurements stay separable -- several cuts share a mesh today,
         * and a cut given its own mesh tomorrow changes one registry line.
         */
        $modelKey = (string) ($type['model_key'] ?? '');
        $geometryKey = $modelKey !== '' && GarmentModels::has($modelKey)
            ? GarmentModels::geometryFor($modelKey)
            : (string) (array_key_first($this->garments()) ?? 'tshirt');

        $geometry = $this->garments()[$geometryKey] ?? [];
        $model = $geometry['model'] ?? null;

        $artworkPath = $input['artwork_path'] ?? null;
        $artworkPath = is_string($artworkPath) && $artworkPath !== '' ? $artworkPath : null;

        /*
         * There is no field saying "printed" or "plain". The artwork's presence
         * is the distinction, so presentation_type is DERIVED here rather than
         * asked for -- two fields that must agree eventually do not.
         *
         * It is still written because the storefront and the old suites read
         * it. Phase 4 drops the column; until then it is kept truthful.
         */
        $hasArtwork = $artworkPath !== null;

        return [
            'name' => trim((string) $input['name']),
            'slug' => trim((string) $input['slug']),
            'garment' => $geometryKey,
            'garment_id' => $garmentId > 0 ? $garmentId : null,
            'artwork_path' => $artworkPath,
            'artwork_x' => (int) $input['artwork_x'],
            'artwork_y' => (int) $input['artwork_y'],
            'artwork_scale' => (int) $input['artwork_scale'],
            'artwork_side' => (string) ($input['artwork_side'] ?? 'front'),
            'presentation_type' => $hasArtwork ? 'designed' : 'standard',
            /* Mirrored from the artwork fields so anything still reading the
               old names sees the same placement, not a stale one. */
            'design_x' => (int) $input['artwork_x'],
            'design_y' => (int) $input['artwork_y'],
            'design_scale' => (int) $input['artwork_scale'],
            'show_3d' => is_string($model) && $model !== '' ? 1 : 0,
            'customization_enabled' => !empty($input['customization_enabled'])
                && !empty($geometry['customizable']) ? 1 : 0,
            'description' => $description === '' ? null : $description,
            'base_price_cents' => Price::cents($input['base_price']),
        ];
    }

    private function imageBelongsTo(int $imageId, int $productId): bool
    {
        if ($productId < 1) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM product_images WHERE id = ? AND product_id = ?');
        $stmt->execute([$imageId, $productId]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array<int,array<string,mixed>> */
    private function uploadedFiles(array $files): array
    {
        if (!isset($files['name']) || !is_array($files['name'])) {
            return ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$files];
        }

        $uploads = [];

        foreach (array_keys($files['name']) as $index) {
            $file = [];

            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $file[$key] = $files[$key][$index] ?? null;
            }

            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $uploads[] = $file;
            }
        }

        return $uploads;
    }

    private function updateImages(int $productId, array $input): void
    {
        $types = (array) ($input['image_type'] ?? []);
        $orders = (array) ($input['sort_order'] ?? []);
        $update = $this->pdo->prepare(
            'UPDATE product_images SET image_type = ?, sort_order = ? WHERE id = ? AND product_id = ?'
        );

        foreach ($orders as $imageId => $order) {
            $type = trim((string) ($types[$imageId] ?? 'product'));
            $type = preg_match('/^[a-z0-9_-]{1,40}$/', $type) ? $type : 'product';
            $update->execute([$type, max(0, min(65535, (int) $order)), (int) $imageId, $productId]);
        }
    }

    /** @param array<int|string,mixed> $imageIds @return array<int,string> */
    private function deleteImages(int $productId, array $imageIds): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $imageIds)), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $params = [...$ids, $productId];
        $select = $this->pdo->prepare('SELECT path FROM product_images WHERE id IN (' . $marks . ') AND product_id = ?');
        $select->execute($params);
        $paths = array_column($select->fetchAll(), 'path');
        $delete = $this->pdo->prepare('DELETE FROM product_images WHERE id IN (' . $marks . ') AND product_id = ?');
        $delete->execute($params);

        return $paths;
    }

    /** @param array<int,array<string,mixed>> $stored @return array<int,int> */
    private function insertImages(int $productId, array $stored): array
    {
        if ($stored === []) {
            return [];
        }

        $next = (int) $this->pdo->query(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = ' . $productId
        )->fetchColumn();
        $insert = $this->pdo->prepare(
            'INSERT INTO product_images
                (product_id, color_id, path, image_type, primary_for, width_px, height_px,
                 mime_type, file_size, sort_order, created_at)
             VALUES (?, NULL, ?, ?, NULL, ?, ?, ?, ?, ?, NOW())'
        );
        $ids = [];

        foreach ($stored as $image) {
            $insert->execute([
                $productId,
                $image['path'],
                'product',
                $image['width'],
                $image['height'],
                $image['mime'],
                $image['bytes'],
                $next++,
            ]);
            $ids[] = (int) $this->pdo->lastInsertId();
        }

        return $ids;
    }

    private function currentPrimaryImageId(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM product_images WHERE product_id = ? AND primary_for = ? LIMIT 1');
        $stmt->execute([$productId, $productId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function firstImageId(int $productId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM product_images WHERE product_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1'
        );
        $stmt->execute([$productId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** @param array<int,array<string,mixed>> $stored */
    private function deleteStored(array $stored): void
    {
        $paths = [];

        foreach ($stored as $image) {
            $paths[] = $image['path'];
        }

        $this->deletePaths($paths);
    }

    /** @param array<int,array<string,mixed>> $uploads */
    private function deleteDesignUploads(array $uploads): void
    {
        foreach ($uploads as $upload) {
            $publicFile = (string) Config::get('app.paths.public') . $upload['path'];

            if (is_file($publicFile)) {
                @unlink($publicFile);
            }

            if (is_string($upload['original'] ?? null) && is_file($upload['original'])) {
                @unlink($upload['original']);
            }
        }
    }

    /** @param array<int,string> $paths */
    private function deletePaths(array $paths): void
    {
        $public = (string) Config::get('app.paths.public');
        $private = (string) Config::get('app.paths.storage') . '/originals/products';

        foreach ($paths as $path) {
            foreach ([
                $public . $path,
                ...array_map(static fn (int $width): string => $public . Image::variant($path, $width), Image::WIDTHS),
                $public . Image::thumbnail($path),
                $private . '/' . basename($path),
            ] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function setPrimaryImage(int $productId, int $imageId): void
    {
        $clear = $this->pdo->prepare('UPDATE product_images SET primary_for = NULL WHERE primary_for = ?');
        $clear->execute([$productId]);

        $path = null;

        if ($imageId > 0) {
            $select = $this->pdo->prepare('SELECT path FROM product_images WHERE id = ? AND product_id = ?');
            $select->execute([$imageId, $productId]);
            $path = $select->fetchColumn();

            if ($path === false) {
                throw new ValidationException(['primary_image_id' => 'الصورة الأساسية لا تتبع هذا المنتج.']);
            }

            $primary = $this->pdo->prepare('UPDATE product_images SET primary_for = ? WHERE id = ?');
            $primary->execute([$productId, $imageId]);
        }

        $derived = $this->pdo->prepare('UPDATE products SET image_path = ? WHERE id = ?');
        $derived->execute([$path === false ? null : $path, $productId]);
    }
}
