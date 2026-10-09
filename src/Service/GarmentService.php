<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\GarmentRepository;
use App\Repository\ProductRepository;
use App\Support\Db;
use App\Support\GarmentModels;
use App\Support\Validator;
use PDO;
use Throwable;

/**
 * Defining a garment type: what it is called, which model shows it, and which
 * colours and sizes it is cut in.
 *
 * A type is upstream of every product built on it, so two rules govern it. Its
 * model comes from the registry and nowhere else, and narrowing its colours is
 * reported before it is done -- a colour removed here stops being offerable on
 * every product of that type, and an owner should know which products that is
 * before they save, not after.
 */
final class GarmentService
{
    /** A cut with more sizes than this is a data-entry accident, not a cut. */
    public const MAX_SIZES = 20;

    public function __construct(
        private readonly GarmentRepository $garments,
        private readonly ProductRepository $products,
        private readonly ProductService $catalog,
        private readonly PDO $pdo,
    ) {
    }

    public static function make(): self
    {
        $pdo = Db::pdo();

        return new self(new GarmentRepository($pdo), new ProductRepository($pdo), ProductService::make(), $pdo);
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return array_map(fn (array $row): array => $this->decorate($row), $this->garments->all());
    }

    /** @return array<int,array<string,mixed>> */
    public function active(): array
    {
        return array_map(fn (array $row): array => $this->decorate($row), $this->garments->active());
    }

    /**
     * One cut by its key, for the storefront.
     *
     * The listing pages address a cut by key rather than by id -- "printed" has
     * to survive a database being reseeded with different auto-increment values,
     * and an id in a URL would not.
     */
    public function byKey(string $key): ?array
    {
        return $key === '' ? null : $this->garments->findByKey($key);
    }

    public function find(int $id): ?array
    {
        $row = $this->garments->find($id);

        return $row === null ? null : $this->decorate($row);
    }

    public function blank(): array
    {
        return [
            'id' => 0,
            'key' => '',
            'name_ar' => '',
            'name_en' => '',
            'model_key' => GarmentModels::defaultKey(),
            'base_product_id' => null,
            'base_product' => null,
            'base_product_name' => null,
            'base_product_printed' => false,
            'editor_problem' => 'النوع الجديد بلا منتج أساسي بعد، فلا يظهر في المحرر.',
            'print_area_w_cm' => '28.0',
            'print_area_h_cm' => '35.0',
            'is_active' => 1,
            'sort_order' => 0,
            'colors' => [],
            'sizes' => [],
            'model_path' => GarmentModels::pathFor(GarmentModels::defaultKey()),
            'model_label' => GarmentModels::labelFor(GarmentModels::defaultKey()),
            'model_shared' => GarmentModels::isShared(GarmentModels::defaultKey()),
            'product_count' => 0,
        ];
    }

    /** @return array<int,int> */
    public function colorIdsFor(int $garmentId): array
    {
        return $garmentId > 0 ? $this->garments->colorIdsFor($garmentId) : [];
    }

    /** @return array<int,int> */
    public function sizeIdsFor(int $garmentId): array
    {
        return $garmentId > 0 ? $this->garments->sizeIdsFor($garmentId) : [];
    }

    /** @return array<int,array<string,mixed>> */
    public function colorsFor(int $garmentId): array
    {
        return $garmentId > 0 ? $this->garments->colorsFor($garmentId) : [];
    }

    /** @return array<int,array<string,mixed>> */
    public function sizesFor(int $garmentId): array
    {
        return $garmentId > 0 ? $this->garments->sizesFor($garmentId) : [];
    }

    /**
     * Which products would lose a colour if this type were narrowed to the
     * given set. Read by the editor to warn before the save, not after.
     *
     * @param array<int,int> $colorIds
     * @return array<int,array<string,mixed>>
     */
    public function impactOfColors(int $garmentId, array $colorIds): array
    {
        return $garmentId > 0 ? $this->garments->productsLosingColors($garmentId, $colorIds) : [];
    }

    /** @throws ValidationException */
    public function save(array $input): int
    {
        $id = max(0, (int) ($input['id'] ?? 0));
        $errors = $this->validate($input, $id);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $baseProductId = (int) ($input['base_product_id'] ?? 0);

        $values = [
            'key' => trim((string) $input['key']),
            'name_ar' => trim((string) $input['name_ar']),
            'name_en' => trim((string) $input['name_en']),
            'model_key' => (string) $input['model_key'],
            'base_product_id' => $baseProductId > 0 ? $baseProductId : null,
            'print_area_w_cm' => (float) $input['print_area_w_cm'],
            'print_area_h_cm' => (float) $input['print_area_h_cm'],
            'is_active' => empty($input['is_active']) ? 0 : 1,
            'sort_order' => (int) ($input['sort_order'] ?? 0),
        ];

        $colorIds = self::idList($input['colors'] ?? []);
        $sizeIds = self::idList($input['sizes'] ?? []);

        try {
            $this->pdo->beginTransaction();

            if ($id > 0) {
                $this->garments->update($id, $values);
            } else {
                $id = $this->garments->insert($values);
            }

            $this->garments->setColors($id, $colorIds);
            $this->garments->setSizes($id, $sizeIds);

            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }

        $this->catalog->forgetCache();

        return $id;
    }

    /** @return array<string,string> */
    private function validate(array $input, int $id): array
    {
        $validator = new Validator($input);
        $validator->required('key', 'المُعرّف')->maxLength('key', 60, 'المُعرّف');
        $validator->required('name_ar', 'الاسم بالعربية')->maxLength('name_ar', 120, 'الاسم بالعربية');
        $validator->required('name_en', 'الاسم بالإنجليزية')->maxLength('name_en', 120, 'الاسم بالإنجليزية');
        $validator->decimal('print_area_w_cm', 1.0, 200.0, 'عرض مساحة الطباعة');
        $validator->decimal('print_area_h_cm', 1.0, 200.0, 'ارتفاع مساحة الطباعة');
        $validator->integer('sort_order', 0, 999, 'الترتيب');

        $key = $validator->string('key');

        if ($key !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) {
            $validator->fail('key', 'المُعرّف يقبل أحرفًا إنجليزية صغيرة وأرقامًا وشرطات فقط.');
        } elseif ($key !== '' && $this->garments->keyExists($key, $id)) {
            $validator->fail('key', 'المُعرّف مستخدم لنوع آخر.');
        }

        /*
         * The model is a key in the registry, not a path. Anything else is
         * refused outright rather than falling back to a default: a type
         * silently shown on the wrong mesh prints artwork in the wrong place.
         */
        $modelKey = (string) ($input['model_key'] ?? '');

        if (!GarmentModels::has($modelKey)) {
            $validator->fail('model_key', 'المجسم المختار غير موجود في السجلّ.');
        }

        /*
         * The base product, if one was named.
         *
         * Leaving it empty is allowed -- a type being drafted has no product
         * yet. Naming one that cannot serve is not: the editor would mount on a
         * product with no variant to sell, and the customer would meet that
         * rather than the administrator.
         */
        $baseProductId = (int) ($input['base_product_id'] ?? 0);

        if ($baseProductId > 0 && $id > 0) {
            $problem = $this->garments->baseProductProblem($id, $baseProductId);

            if ($problem !== null) {
                $validator->fail('base_product_id', $problem);
            }
        } elseif ($baseProductId > 0 && $id < 1) {
            /* A product cannot belong to a type that does not exist yet. */
            $validator->fail('base_product_id',
                'احفظ النوع أولًا، ثم اختر منتجه الأساسي من منتجاته.');
        }

        $colorIds = self::idList($input['colors'] ?? []);
        $sizeIds = self::idList($input['sizes'] ?? []);

        $knownColors = array_map('intval', array_column($this->products->colors(), 'id'));
        $knownSizes = array_map('intval', array_column($this->products->sizes(), 'id'));

        $unknownColors = array_diff($colorIds, $knownColors);
        $unknownSizes = array_diff($sizeIds, $knownSizes);

        if ($unknownColors !== []) {
            $validator->fail('colors', 'لون غير معروف: ' . implode('، ', $unknownColors) . '.');
        }

        if ($unknownSizes !== []) {
            $validator->fail('sizes', 'مقاس غير معروف: ' . implode('، ', $unknownSizes) . '.');
        }

        if (count($sizeIds) > self::MAX_SIZES) {
            $validator->fail('sizes', 'الحدّ الأقصى ' . self::MAX_SIZES . ' مقاسًا للنوع الواحد.');
        }

        /*
         * A type with no colours or no sizes can carry no variants, so a
         * product built on it would have nothing to sell. Allowed while the
         * type is being drafted, refused once it is active.
         */
        if (!empty($input['is_active']) && ($colorIds === [] || $sizeIds === [])) {
            $validator->fail($colorIds === [] ? 'colors' : 'sizes',
                'النوع المفعَّل يحتاج لونًا واحدًا ومقاسًا واحدًا على الأقل.');
        }

        return $validator->errors();
    }

    /** Whole positive ids from whatever the form sent, deduplicated.
     *  @return array<int,int> */
    private static function idList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $value) {
            /*
             * Integer-like only. (int) on an array is 1 and (int) "3abc" is 3,
             * both silently, so a malformed request would select a real colour
             * nobody chose. Refusing the value is the only honest reading.
             */
            if (is_array($value) || !is_numeric($value)) {
                continue;
            }

            $id = (int) $value;

            if ($id > 0 && (string) $id === trim((string) $value)) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** @return array<int,array<string,mixed>> the products this type may be based on */
    public function baseProductCandidates(int $garmentId): array
    {
        return $garmentId > 0 ? $this->garments->baseProductCandidates($garmentId) : [];
    }

    /**
     * The types the customer's editor may offer.
     *
     * Active, and with a base product that can still serve. A type without one
     * is left out entirely rather than shown and then refused -- a cut a
     * customer can pick but not order is worse than a cut they never saw.
     *
     * @return array<int,array<string,mixed>>
     */
    public function editorReady(): array
    {
        return array_values(array_filter(
            $this->active(),
            fn (array $type): bool => $this->garments->baseProductFor((int) $type['id']) !== null
        ));
    }

    public function baseProductFor(int $garmentId): ?array
    {
        return $garmentId > 0 ? $this->garments->baseProductFor($garmentId) : null;
    }

    /**
     * Why this type is not in the editor, or null when it is.
     *
     * Written for the administrator, in the terms they can act on: the screen
     * prints it beside the type rather than leaving them to wonder why the
     * customer's editor is empty.
     */
    public function editorProblem(array $type): ?string
    {
        $id = (int) $type['id'];

        if ((int) $type['is_active'] !== 1) {
            return 'النوع معطَّل، فلا يظهر في المحرر.';
        }

        $baseId = (int) ($type['base_product_id'] ?? 0);

        if ($baseId < 1) {
            return 'لا منتج أساسي لهذا النوع، فلا يظهر في المحرر. '
                . 'المنتج الأساسي هو ما يصير إليه الطلب المخصّص: منه يأتي السعر والمخزون واللون والمقاس.';
        }

        $problem = $this->garments->baseProductProblem($id, $baseId);

        return $problem === null ? null : $problem . ' ولذلك لا يظهر النوع في المحرر.';
    }

    /** The registry facts a screen needs beside the row. */
    private function decorate(array $row): array
    {
        $key = (string) $row['model_key'];
        $id = (int) $row['id'];
        $baseId = (int) ($row['base_product_id'] ?? 0);
        $base = $baseId > 0 ? $this->products->find($baseId) : null;

        return $row + [
            'colors' => $this->garments->colorIdsFor($id),
            'sizes' => $this->garments->sizeIdsFor($id),
            'model_path' => GarmentModels::pathFor($key),
            'model_label' => GarmentModels::labelFor($key),
            'model_shared' => GarmentModels::isShared($key),
            'model_missing' => !GarmentModels::has($key),
            'product_count' => $this->garments->productCount($id),
            'base_product' => $base,
            'base_product_name' => $base === null ? null : (string) $base['name'],
            /* A printed base product is allowed but odd: every custom order of
               this cut would be sold as a garment that already carries a print.
               Warned about on the screen, never refused. */
            'base_product_printed' => $base !== null && ($base['artwork_path'] ?? null) !== null,
            'editor_problem' => $this->editorProblem($row),
        ];
    }
}
