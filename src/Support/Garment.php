<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * The one place print geometry is turned into numbers.
 *
 * config/garments.php holds the measured UV bounds; everything else — pixel
 * rectangles, centimetres, DPI — is derived here and handed out. Nothing
 * downstream, PHP or React, may restate a constant: re-measuring the model has
 * to be a one-file change.
 */
final class Garment
{
    public const SIDES = ['front', 'back'];

    public function __construct(
        private readonly string $key,
        private readonly array $spec,
    ) {
    }

    public static function make(string $key = 'tshirt'): self
    {
        $garments = Config::file('garments');

        if (!isset($garments[$key])) {
            return new self($key, [
                'customizable' => false,
                'print_area' => [],
            ]);
        }

        return new self($key, $garments[$key] + ['customizable' => true]);
    }

    public function isCustomizable(): bool
    {
        return $this->spec['customizable'];
    }

    public function canvasPx(): int
    {
        return (int) $this->spec['canvas_px'];
    }

    /** @return array{w:float,h:float} */
    public function printSizeCm(): array
    {
        return [
            'w' => (float) $this->spec['print_size_cm']['w'],
            'h' => (float) $this->spec['print_size_cm']['h'],
        ];
    }

    /**
     * Pixel rectangle for one side of the UV atlas.
     *
     * v runs UP the garment while canvas y runs down, hence the (1 - v1) origin.
     * The CanvasTexture keeps its default flipY, which maps v-up to canvas-up.
     *
     * @return array{x:int,y:int,w:int,h:int}
     */
    public function rect(string $side = 'front'): array
    {
        $area = $this->area($side);
        $size = $this->canvasPx();

        return [
            'x' => (int) round($area['u0'] * $size),
            'y' => (int) round((1 - $area['v1']) * $size),
            'w' => (int) round(($area['u1'] - $area['u0']) * $size),
            'h' => (int) round(($area['v1'] - $area['v0']) * $size),
        ];
    }

    /** @return array<string,array{x:int,y:int,w:int,h:int}> */
    public function rects(): array
    {
        $rects = [];

        foreach (self::SIDES as $side) {
            $rects[$side] = $this->rect($side);
        }

        return $rects;
    }

    /** The configured UV panel is the complete editable print area. */
    public function designRect(string $side = 'front'): array
    {
        return $this->rect($side);
    }

    /**
     * The size a plain uploaded file is placed at.
     *
     * Distinct from printSizeCm(), which is the whole panel. Falling back to
     * the panel keeps a garment that has not declared one working, but any
     * garment people actually upload to should declare it: measuring artwork
     * against the full panel is what made the resolution check unusable.
     *
     * @return array{w:float,h:float}
     */
    public function artworkSizeCm(): array
    {
        $size = $this->spec['artwork_size_cm'] ?? [];

        if (!isset($size['w'], $size['h']) || (float) $size['w'] <= 0 || (float) $size['h'] <= 0) {
            return $this->printSizeCm();
        }

        return ['w' => (float) $size['w'], 'h' => (float) $size['h']];
    }

    /** @return array<string,array{x:int,y:int,w:int,h:int}> */
    public function designRects(): array
    {
        $rects = [];

        foreach (self::SIDES as $side) {
            $rects[$side] = $this->designRect($side);
        }

        return $rects;
    }

    /**
     * How many centimetres of cloth one side's rectangle covers.
     *
     * @return array{w:float,h:float}
     */
    public function areaCm(string $side = 'front'): array
    {
        $area  = $this->area($side);
        $scale = $this->spec['uv_scale_cm'];

        return [
            'w' => round(($area['u1'] - $area['u0']) * $scale['u'], 2),
            'h' => round(($area['v1'] - $area['v0']) * $scale['v'], 2),
        ];
    }

    /**
     * Pixels per centimetre inside the rectangle, per axis.
     *
     * The two differ by well under one percent on this model, which is why no
     * stretch compensation exists anywhere in the codebase.
     *
     * @return array{x:float,y:float,anisotropy:float}
     */
    public function pixelsPerCm(string $side = 'front'): array
    {
        $rect = $this->rect($side);
        $cm   = $this->areaCm($side);

        $x = $rect['w'] / $cm['w'];
        $y = $rect['h'] / $cm['h'];

        return ['x' => $x, 'y' => $y, 'anisotropy' => $y / $x - 1];
    }

    /**
     * The editor's working canvas: the print area at a round resolution whose
     * 300 DPI export is an exact multiple, with the height following the true
     * print proportions rather than the pixel rectangle's.
     *
     * @return array{w:int,h:int}
     */
    public function workPx(int $width = 1000): array
    {
        $cm = $this->printSizeCm();

        return ['w' => $width, 'h' => (int) round(($width * $cm['h']) / $cm['w'])];
    }

    /** Pixel size the print export must have to hit the target DPI. */
    public function exportPx(int $dpi = 300): array
    {
        $size = $this->printSizeCm();

        return [
            'w' => (int) round($size['w'] / 2.54 * $dpi),
            'h' => (int) round($size['h'] / 2.54 * $dpi),
        ];
    }

    /**
     * Everything the editor island needs to place artwork, and nothing it could
     * derive wrongly on its own.
     */
    public function toArray(): array
    {
        if (!$this->isCustomizable()) {
            return [
                'key'            => $this->key,
                'customizable'   => false,
                'print_area'     => [],
                'uv_scale_cm'    => [],
                'print_size_cm'  => [],
                'canvas_px'      => 0,
                'chest_rect_px'  => [],
                'panel_rect_px'  => [],
                'design_rect_px' => [],
                'area_cm'        => [],
                'export_px'      => [],
                'work_px'        => [],
                'dpi'            => [],
            ];
        }

        return [
            'key'           => $this->key,
            'customizable'  => true,
            'print_area'    => $this->spec['print_area'],
            'uv_scale_cm'   => $this->spec['uv_scale_cm'],
            'print_size_cm' => $this->spec['print_size_cm'],
            'canvas_px'     => $this->canvasPx(),
            // The full UV panel, as measured off the mesh.
            'chest_rect_px' => $this->rect('front'),
            'panel_rect_px' => $this->rects(),
            // What the customer may actually draw in and what gets exported.
            'design_rect_px' => $this->designRects(),
            'area_cm'       => ['front' => $this->areaCm('front'), 'back' => $this->areaCm('back')],
            'export_px'     => $this->exportPx(),
            'work_px'       => $this->workPx(),
            'dpi'           => [
                'target' => (int) Config::get('print.dpi.target', 300),
                'warn'   => (int) Config::get('print.dpi.warn', 150),
                'reject' => (int) Config::get('print.dpi.reject', 100),
            ],
        ];
    }

    /** @return array{u0:float,u1:float,v0:float,v1:float} */
    private function area(string $side): array
    {
        if (!isset($this->spec['print_area'][$side])) {
            throw new RuntimeException('Unknown print side: ' . $side);
        }

        return $this->spec['print_area'][$side];
    }
}
