<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\DesignRepository;
use App\Repository\HeroSlideRepository;
use App\Repository\ProductRepository;
use App\Support\Cache;
use App\Support\CacheInterface;
use App\Support\Config;
use App\Support\Garment;
use App\Support\HeroModels;
use App\Support\Lang;
use App\Support\Validator;

/**
 * Takes arrays, returns arrays. Knows nothing about requests, sessions or
 * markup, so the HTML page and the JSON endpoint call exactly the same code.
 */
final class HeroSlideService
{
    public const CACHE_KEY = 'hero.slides';

    public const STAT_COUNT = 2;

    /** Elements the editor may nudge. Anything else in `layout` is dropped. */
    public const ELEMENTS = ['tab', 'wordmark', 'tagline', 'stats'];

    /**
     * Where a design sits on the model.
     *
     * Read from the cart, not restated. A hero slide and a cart line describe
     * the same thing -- artwork on the front, the back, or both -- and a second
     * list here would be a second thing to keep in step for no gain. See
     * CartService::PLACEMENTS.
     */
    public const PLACEMENTS = CartService::PLACEMENTS;

    /** Largest nudge, in percent of the card, in either direction. */
    private const MAX_NUDGE = 45.0;

    /** id => hex, read once per instance. */
    private ?array $colors = null;

    /** design id => its rows from design_layers, read once per design. */
    private array $layers = [];

    public function __construct(
        private readonly HeroSlideRepository $slides,
        private readonly CacheInterface $cache,
        private readonly DesignRepository $designs,
        private readonly ProductRepository $products,
    ) {
    }

    public static function make(): self
    {
        return new self(
            HeroSlideRepository::make(),
            Cache::store(),
            DesignRepository::make(),
            ProductRepository::make(),
        );
    }

    /** @return array<int,array<string,mixed>> active slides, in order */
    public function activeSlides(): array
    {
        return $this->cache->remember(
            self::CACHE_KEY,
            fn (): array => array_map($this->hydrate(...), $this->slides->active())
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function allSlides(): array
    {
        return array_map($this->hydrate(...), $this->slides->all());
    }

    public function find(int $id): ?array
    {
        $row = $this->slides->find($id);

        return $row === null ? null : $this->hydrate($row);
    }

    /** The shape the hero component and its editor both consume. */
    public function payload(): array
    {
        return self::withGarment($this->activeSlides());
    }

    /**
     * Slides plus the print geometry their model was measured with.
     *
     * One stage serves every slide, so one geometry is published: the first
     * model a slide actually names, and the registry's default when no slide
     * names one. Read from config/hero-models.php rather than a garment key
     * spelled out here, so adding a second model does not leave the artwork
     * being fitted to the first one's rectangles.
     *
     * @param array<int,array<string,mixed>> $slides
     */
    public static function withGarment(array $slides): array
    {
        $key = HeroModels::DEFAULT_KEY;

        foreach ($slides as $slide) {
            if (!empty($slide['has_model']) && HeroModels::has((string) $slide['model_key'])) {
                $key = (string) $slide['model_key'];

                break;
            }
        }

        return [
            'slides'  => $slides,
            'garment' => HeroModels::garment($key) ?? Config::file('garments')['tshirt'],
        ];
    }

    /**
     * The tables the editor's preview resolves a draft slide against.
     *
     * The editor has to turn "colour 3, design 2, placement both" into the same
     * hex and the same layer list the server would have produced, without a
     * round trip -- an administrator dragging a select should see the shirt
     * change, not wait for one.
     *
     * `placementSides` is the part worth naming. Which sides a placement prints
     * on is a rule, and it stays here: the editor reads the answer out of this
     * map rather than knowing that "both" means front and back. The values
     * themselves are CartService::PLACEMENTS, as everywhere else.
     */
    public function editorLookups(): array
    {
        $sides = [];

        foreach (self::PLACEMENTS as $placement) {
            $sides[$placement] = array_values(array_intersect(
                $placement === 'both' ? Garment::SIDES : [$placement],
                Garment::SIDES
            ));
        }

        $layers = [];

        foreach ($this->designs->all() as $design) {
            $id = (int) $design['id'];

            $layers[$id] = array_map(static fn (array $layer): array => [
                'path'  => (string) $layer['image_path'],
                'x'     => (int) $layer['position_x'],
                'y'     => (int) $layer['position_y'],
                'scale' => (int) $layer['scale_pct'],
            ], $this->designs->layersForDesign($id));
        }

        return [
            'models'         => array_map(
                static fn (array $model): string => (string) $model['file'],
                HeroModels::all()
            ),
            'colors'         => array_column($this->products->colors(), 'hex', 'id'),
            'designLayers'   => $layers,
            'placementSides' => $sides,
        ];
    }

    public function blank(): array
    {
        return [
            'id'                => 0,
            'has_model'         => true,
            'model_key'         => HeroModels::DEFAULT_KEY,
            'color_id'          => $this->colorIdForHex('#E7E4DC'),
            'design_id'         => null,
            'placement'         => self::PLACEMENTS[0],
            'show_badges'       => true,
            'parallax_enabled'  => true,
            'model_path'        => HeroModels::file(HeroModels::DEFAULT_KEY) ?? '',
            'poster_path'       => '/images/hero-poster.webp',
            'garment_color'     => '#E7E4DC',
            'design_image_path' => null,
            'background_value'  => '#141414',
            /*
             * Empty, on purpose.
             *
             * These used to arrive filled with the first slide's own words, so
             * a new slide that was saved without being read published a
             * duplicate of a live one -- and hero_slides #17 is one that was.
             * A new slide carries only what it cannot be without: a model to
             * show, a colour, a background, and where its button goes.
             */
            'tab_word'          => '',
            'wordmark'          => '',
            'tagline'           => '',
            'stats'             => [
                ['value' => '', 'label' => ''],
                ['value' => '', 'label' => ''],
            ],
            'layout'            => [],
            /*
             * The default destination is the design editor.
             *
             * A new slide's button is the shop's loudest invitation, and what it
             * invites people to do is design a shirt -- so it points at the
             * editor rather than at the catalogue. It stays a plain editable
             * field: an administrator who wants a slide pointing somewhere else
             * types somewhere else.
             */
            'cta_label'         => Lang::get('admin.slide.default_cta'),
            'cta_url'           => '/design.php',
            'sort_order'        => $this->slides->nextSortOrder(),
            'is_active'         => true,
        ];
    }

    /** @return array<string,string> field => message; empty means valid */
    public function validate(array $input): array
    {
        $v = new Validator($input);

        $v->assetPath('poster_path', Lang::get('admin.slide.f.poster_path'), '', true);
        $v->assetPath('design_image_path', Lang::get('admin.slide.f.design_image_path'), '', true);

        $this->validateModel($input, $v);

        // A hex value or an uploaded image; the '#' tells the two apart, which
        // is why the column needs no companion type.
        $background = $v->string('background_value');

        if (!Validator::isHex($background) && !Validator::isAssetPath($background)) {
            $v->fail('background_value', Lang::get('admin.slide.err.background'));
        }

        $v->maxLength('tab_word', 40, Lang::get('admin.slide.f.tab_word'));
        $v->maxLength('wordmark', 40, Lang::get('admin.slide.f.wordmark'));
        $v->maxLength('tagline', 200, Lang::get('admin.slide.f.tagline'));
        $v->maxLength('cta_label', 120, Lang::get('admin.slide.f.cta_label'));
        $v->link('cta_url', Lang::get('admin.slide.f.cta_url'));

        $v->jsonList('stats', Lang::get('admin.slide.f.stats'), self::STAT_COUNT, static function (array $stat, int $n): ?string {
            $value = trim((string) ($stat['value'] ?? ''));
            $label = trim((string) ($stat['label'] ?? ''));

            if ($value === '' || $label === '') {
                return Lang::get('admin.slide.err.stat_empty', ['n' => $n]);
            }

            if (mb_strlen($value) > 20 || mb_strlen($label) > 40) {
                return Lang::get('admin.slide.err.stat_long', ['n' => $n]);
            }

            return null;
        });

        if (count(Validator::decodeList($input['stats'] ?? null)) !== self::STAT_COUNT
            && !isset($v->errors()['stats'])) {
            $v->fail('stats', Lang::get('admin.slide.err.stat_count'));
        }

        foreach (self::decodeLayout($input['layout'] ?? null) as $element => $nudge) {
            if (abs($nudge['dx']) > self::MAX_NUDGE || abs($nudge['dy']) > self::MAX_NUDGE) {
                $v->fail('layout', Lang::get('admin.slide.err.layout', ['element' => $element]));

                break;
            }
        }

        return $v->errors();
    }

    /**
     * The model half: what the slide shows, and whether it shows one at all.
     *
     * A slide with no model is not a slide with empty model fields -- it is a
     * background and some words, and the editor hides the rest. So nothing
     * below is asked of it, and a stale model_key left in the form by a
     * checkbox that was just cleared is not a reason to refuse the save.
     */
    private function validateModel(array $input, Validator $v): void
    {
        if (empty($input['has_model'])) {
            return;
        }

        $modelKey = trim((string) ($input['model_key'] ?? ''));

        if (!HeroModels::has($modelKey)) {
            $v->fail('model_key', Lang::get('admin.slide.err.model'));
        }

        $colorId = self::optionalId($input['color_id'] ?? null);

        if ($colorId === null) {
            $v->fail('color_id', Lang::get('admin.slide.err.colour'));
        } elseif ($this->colorHex($colorId) === null) {
            $v->fail('color_id', Lang::get('admin.slide.err.colour_gone'));
        }

        $designId = self::optionalId($input['design_id'] ?? null);

        /* No design is a plain shirt, which is a complete answer, not a gap. */
        if ($designId === null) {
            return;
        }

        if ($this->designs->find($designId) === null) {
            $v->fail('design_id', Lang::get('admin.slide.err.design_gone'));
        }

        $placement = trim((string) ($input['placement'] ?? ''));

        if ($placement === '') {
            $v->fail('placement', Lang::get('admin.slide.err.placement_missing'));
        } elseif (!in_array($placement, self::PLACEMENTS, true)) {
            $v->fail('placement', Lang::get('admin.slide.err.placement_unknown'));
        }
    }

    /** @throws ValidationException */
    public function save(array $input): int
    {
        $errors = $this->validate($input);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $id     = (int) ($input['id'] ?? 0);
        $values = $this->normalise($input);

        if ($id > 0) {
            $this->slides->update($id, $values);
        } else {
            $id = $this->slides->insert($values);
        }

        $this->cache->forget(self::CACHE_KEY);

        return $id;
    }

    public function delete(int $id): void
    {
        $this->slides->delete($id);
        $this->cache->forget(self::CACHE_KEY);
    }

    public function toggle(int $id): bool
    {
        $slide = $this->slides->find($id);

        if ($slide === null) {
            return false;
        }

        $next = !(bool) $slide['is_active'];
        $this->slides->setActive($id, $next);
        $this->cache->forget(self::CACHE_KEY);

        return $next;
    }

    /** @param array<int,int|string> $ids */
    public function reorder(array $ids): void
    {
        $this->slides->reorder(array_map('intval', $ids));
        $this->cache->forget(self::CACHE_KEY);
    }

    private function normalise(array $input): array
    {
        $optional = static function (mixed $v): ?string {
            $v = trim((string) ($v ?? ''));

            return $v === '' ? null : $v;
        };

        $stats = [];

        foreach (Validator::decodeList($input['stats'] ?? null) as $stat) {
            $stats[] = [
                'value' => trim((string) ($stat['value'] ?? '')),
                'label' => trim((string) ($stat['label'] ?? '')),
            ];
        }

        $hasModel = !empty($input['has_model']);
        $modelKey = trim((string) ($input['model_key'] ?? HeroModels::DEFAULT_KEY));
        $designId = self::optionalId($input['design_id'] ?? null);

        /*
         * model_path is no longer the administrator's to type -- it follows the
         * key. It stays on the row because the column is NOT NULL and because a
         * slide that predates the registry still has to name its file.
         */
        $modelPath = HeroModels::file($modelKey) ?? trim((string) ($input['model_path'] ?? ''));

        return [
            'has_model'         => $hasModel ? 1 : 0,
            'model_key'         => $modelKey,
            'color_id'          => self::optionalId($input['color_id'] ?? null),
            'design_id'         => $designId,
            // A slide with no design has nowhere to put one; the column keeps
            // its default rather than a stale side from a design since removed.
            'placement'         => $designId === null
                ? self::PLACEMENTS[0]
                : trim((string) ($input['placement'] ?? self::PLACEMENTS[0])),
            'show_badges'       => empty($input['show_badges']) ? 0 : 1,
            'parallax_enabled'  => empty($input['parallax_enabled']) ? 0 : 1,
            'model_path'        => $modelPath,
            'poster_path'       => $optional($input['poster_path'] ?? null),
            /*
             * Mirrors the chosen colour. The form posts an id now, but the old
             * column is still what paints a slide whose id could not be matched,
             * so it is kept in step rather than left to rot.
             */
            'garment_color'     => $this->colorHex(self::optionalId($input['color_id'] ?? null))
                ?? strtoupper(trim((string) ($input['garment_color'] ?? '#E7E4DC'))),
            'design_image_path' => $optional($input['design_image_path'] ?? null),
            'background_value'  => trim((string) $input['background_value']),
            'tab_word'          => $optional($input['tab_word'] ?? null),
            'wordmark'          => $optional($input['wordmark'] ?? null),
            'tagline'           => $optional($input['tagline'] ?? null),
            'stats'             => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'layout'            => ($layout = self::decodeLayout($input['layout'] ?? null)) === []
                ? null
                : json_encode($layout, JSON_THROW_ON_ERROR),
            'cta_label'         => $optional($input['cta_label'] ?? null),
            'cta_url'           => $optional($input['cta_url'] ?? null),
            'sort_order'        => max(0, (int) ($input['sort_order'] ?? 0)),
            'is_active'         => empty($input['is_active']) ? 0 : 1,
        ];
    }

    private function hydrate(array $row): array
    {
        $row['id']         = (int) $row['id'];
        $row['sort_order'] = (int) $row['sort_order'];
        $row['is_active']  = (bool) $row['is_active'];
        $row['stats']      = Validator::decodeList($row['stats']);
        $row['layout']     = self::decodeLayout($row['layout'] ?? null);

        $row['has_model']   = (bool) ($row['has_model'] ?? true);
        $row['show_badges'] = (bool) ($row['show_badges'] ?? true);
        $row['parallax_enabled'] = (bool) ($row['parallax_enabled'] ?? true);
        $row['model_key']   = (string) ($row['model_key'] ?? HeroModels::DEFAULT_KEY);
        $row['color_id']    = self::optionalId($row['color_id'] ?? null);
        $row['design_id']   = self::optionalId($row['design_id'] ?? null);
        $row['placement']   = (string) ($row['placement'] ?? self::PLACEMENTS[0]);

        /*
         * Three derived values, so that neither the template nor the browser
         * has to know how a slide is stored.
         *
         * model_file is the registry's file for the key -- null when the key
         * has been removed from config since the slide was saved, which the
         * view reads as "no model" rather than fetching a path that is not
         * there. garment_color prefers the palette row and falls back to the
         * old hex column, so a slide the migration could not match still
         * paints the colour it always painted.
         */
        $row['model_file']    = $row['has_model'] ? HeroModels::file($row['model_key']) : null;
        $row['garment_color'] = $this->colorHex($row['color_id'])
            ?? (string) ($row['garment_color'] ?? '#E7E4DC');
        $row['design_layers'] = $row['has_model']
            ? $this->designLayers($row['design_id'], $row['design_image_path'] ?? null, $row['placement'])
            : [];

        return $row;
    }

    /**
     * The artwork a slide puts on its model, in the shape drawDesignLayers()
     * reads -- the same shape the product page hands the same function.
     *
     * `placement` chooses the sides; the design's own layers are drawn on each
     * of them. That is the expansion CustomDesignService::stateForUpload()
     * already makes for a customer's artwork, and a hero slide saying "both"
     * means the same thing there as it does in the cart.
     *
     * A slide whose design_image_path matched no catalogue design keeps
     * printing that image, as one centred layer. It is the same picture the
     * hero drew before this column existed.
     *
     * @return array<int,array{path:string,side:string,x:int,y:int,scale:int}>
     */
    private function designLayers(?int $designId, ?string $fallbackImage, string $placement): array
    {
        $sides = $placement === 'both' ? Garment::SIDES : [$placement];
        $sides = array_values(array_intersect($sides, Garment::SIDES));

        if ($sides === []) {
            return [];
        }

        $source = $designId === null
            ? ($fallbackImage === null ? [] : [[
                'image_path' => $fallbackImage,
                'position_x' => 50,
                'position_y' => 50,
                'scale_pct'  => 100,
            ]])
            : ($this->layers[$designId] ??= $this->designs->layersForDesign($designId));

        $layers = [];

        foreach ($sides as $side) {
            foreach ($source as $layer) {
                $layers[] = [
                    'path'  => (string) $layer['image_path'],
                    'side'  => $side,
                    'x'     => (int) ($layer['position_x'] ?? 50),
                    'y'     => (int) ($layer['position_y'] ?? 50),
                    'scale' => (int) ($layer['scale_pct'] ?? 100),
                ];
            }
        }

        return $layers;
    }

    /** The palette, read once per instance rather than once per slide. */
    private function colorHex(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $this->colors ??= array_column($this->products->colors(), 'hex', 'id');

        return isset($this->colors[$id]) ? (string) $this->colors[$id] : null;
    }

    private function colorIdForHex(string $hex): ?int
    {
        $this->colors ??= array_column($this->products->colors(), 'hex', 'id');
        $id = array_search(strtoupper($hex), array_map('strtoupper', $this->colors), true);

        return $id === false ? null : (int) $id;
    }

    /** An empty select and a zero both mean "nothing chosen", never id 0. */
    private static function optionalId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * Keeps only known elements and numeric nudges, so whatever is in the
     * column can be trusted by the template without further checking.
     *
     * @return array<string,array{dx:float,dy:float}>
     */
    public static function decodeLayout(mixed $raw): array
    {
        if (is_string($raw)) {
            try {
                $raw = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        $layout = [];

        foreach (self::ELEMENTS as $element) {
            $nudge = $raw[$element] ?? null;

            if (!is_array($nudge) || !is_numeric($nudge['dx'] ?? null) || !is_numeric($nudge['dy'] ?? null)) {
                continue;
            }

            $dx = round((float) $nudge['dx'], 2);
            $dy = round((float) $nudge['dy'], 2);

            if ($dx === 0.0 && $dy === 0.0) {
                continue;
            }

            $layout[$element] = ['dx' => $dx, 'dy' => $dy];
        }

        return $layout;
    }
}
