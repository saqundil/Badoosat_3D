<?php

declare(strict_types=1);

namespace App\Service;

use App\Print\PrintDriverFactory;
use App\Print\PrintDriverInterface;
use App\Repository\CustomDesignRepository;
use App\Repository\OrderRepository;
use App\Repository\PrintFileRepository;
use App\Support\Config;
use App\Support\Uploader;
use RuntimeException;

/**
 * Knows what a print file must contain; knows nothing about how pixels are
 * pushed. Everything that needs an extension or a binary goes through
 * PrintDriverInterface, so the same code runs on shared hosting and on a VPS.
 */
final class PrintFileService
{
    public function __construct(
        private readonly CustomDesignRepository $designs,
        private readonly OrderRepository $orders,
        private readonly PrintFileRepository $printFiles,
        private readonly PrintDriverInterface $driver,
    ) {
    }

    public static function make(?PrintDriverInterface $driver = null): self
    {
        return new self(
            CustomDesignRepository::make(),
            OrderRepository::make(),
            PrintFileRepository::make(),
            $driver ?? PrintDriverFactory::shared()
        );
    }

    public function driverName(): string
    {
        return method_exists($this->driver, 'name') ? $this->driver->name() : 'unknown';
    }

    /**
     * Effective DPI of artwork at the size it will actually be printed.
     *
     * getimagesize is core PHP: this check works on every host, with no
     * extension and no binary, which is why it runs before the sale.
     *
     * The size measured against is artwork_size_cm -- a chest print -- and not
     * print_size_cm, which is the whole printable panel from shoulder to hem.
     * Measuring against the panel is what this used to do, and it asked 15.6
     * megapixels of a customer merely to avoid a warning: a file larger than
     * the upload limit would accept, so nothing that passed could be sent.
     *
     * @return array{dpi:int,quality:string,message:string,width:int,height:int,vector:bool}
     */
    public function inspect(int $widthPx, int $heightPx, string $mime = 'image/png'): array
    {
        $size = Config::get('garments.tshirt.artwork_size_cm')
            ?: Config::get('garments.tshirt.print_size_cm');
        $widthIn  = $size['w'] / 2.54;
        $heightIn = $size['h'] / 2.54;

        // Vector artwork has no resolution ceiling.
        if ($mime === 'image/svg+xml') {
            return [
                'dpi'     => 0,
                'quality' => 'ok',
                'message' => 'تصميم متجهي — يُطبع بأي مقاس دون فقد دقّة.',
                'width'   => $widthPx,
                'height'  => $heightPx,
                'vector'  => true,
            ];
        }

        if ($widthPx < 1 || $heightPx < 1) {
            return [
                'dpi'     => 0,
                'quality' => 'reject',
                'message' => 'تعذّر قراءة أبعاد الصورة.',
                'width'   => $widthPx,
                'height'  => $heightPx,
                'vector'  => false,
            ];
        }

        $dpi = (int) floor(min($widthPx / $widthIn, $heightPx / $heightIn));

        $warn   = (int) Config::get('print.dpi.warn', 150);
        $reject = (int) Config::get('print.dpi.reject', 100);

        /*
         * Told in the terms a customer can act on: what they have, what is
         * wanted, and the pixel dimensions that would reach it at this print
         * size. "Low resolution" on its own gives them nothing to go and fix.
         */
        $needed = static function (int $target) use ($size): string {
            return (int) ceil($target * $size['w'] / 2.54) . '×'
                . (int) ceil($target * $size['h'] / 2.54) . ' بكسل';
        };

        $at = ' عند مقاس الطباعة ' . $size['w'] . '×' . $size['h'] . ' سم';

        [$quality, $message] = match (true) {
            $dpi < $reject => ['reject',
                'الدقّة ' . $dpi . ' DPI' . $at . ' — منخفضة جدًا للطباعة. '
                . 'المطلوب ' . $reject . ' DPI على الأقل، أي ' . $needed($reject)
                . ' (والأفضل ' . $warn . ' DPI، أي ' . $needed($warn) . ').'],
            $dpi < $warn => ['warn',
                'الدقّة ' . $dpi . ' DPI' . $at . ' — مقبولة، وقد تظهر الحواف ناعمة. '
                . 'لأفضل نتيجة استخدم ' . $warn . ' DPI، أي ' . $needed($warn) . '.'],
            default => ['ok', 'الدقّة ' . $dpi . ' DPI' . $at . ' — مناسبة للطباعة.'],
        };

        return [
            'dpi'     => $dpi,
            'quality' => $quality,
            'message' => $message,
            'width'   => $widthPx,
            'height'  => $heightPx,
            'vector'  => false,
        ];
    }

    /**
     * Stores an uploaded design and records the verdict against it.
     *
     * @param array $file one entry of $_FILES
     * @return array{token:string,design_id:int,path:string,check:array}
     * @throws ValidationException
     */
    public function registerDesign(array $file, string $placement, ?Uploader $uploader = null): array
    {
        if (!in_array($placement, CartService::PLACEMENTS, true)) {
            throw new ValidationException(['placement' => 'موضع الطباعة غير معروف.']);
        }

        $stored = ($uploader ?? Uploader::make())->store($file, 'designs');
        $check  = $this->inspect($stored['width'], $stored['height'], $stored['mime']);

        $token = bin2hex(random_bytes(16));

        $designId = $this->designs->insert([
            'token'         => $token,
            // The quick uploader on the product page is the same door as
            // upload.php, just with fewer questions asked.
            'source'        => 'upload',
            'image_path'    => $stored['path'],
            'original_path' => $stored['original'],
            'mime'          => $stored['mime'],
            'width_px'      => $stored['width'],
            'height_px'     => $stored['height'],
            'dpi'           => $check['dpi'],
            'quality'       => $check['quality'],
            'placement'     => $placement,
        ]);

        return [
            'token'     => $token,
            'design_id' => $designId,
            'path'      => $stored['path'],
            'check'     => $check,
        ];
    }

    /**
     * Produces the press file and the agreement preview for one artwork.
     *
     * Keyed on the design and not on the order line, because that is what the
     * press actually consumes. A team ordering ten shirts across five sizes
     * sends one sheet to the press; producing it five times would give the
     * operator five identical PDFs and five chances to pick the wrong one.
     *
     * One sheet per printed side. A design whose front and back differ carries
     * a second press file, and gets a 'front' and a 'back' sheet; everything
     * else -- one side, or an upload placed on both -- is one 'single' sheet,
     * exactly as before.
     *
     * Runs from the queue, so it may take its time.
     *
     * @return array<int,array<string,mixed>> the print_files rows, single or front then back
     */
    public function produce(int $customDesignId, ?int $orderId = null): array
    {
        $design = $this->designs->find($customDesignId);

        if ($design === null) {
            throw new RuntimeException('Custom design ' . $customDesignId . ' is missing.');
        }

        $rows = [];

        $sides = empty($design['back_original_path']) ? ['single'] : ['front', 'back'];

        foreach ($sides as $side) {
            // The queue is at-least-once by design: a job that timed out halfway
            // is retried, and re-rendering a sheet that already exists would only
            // burn minutes and orphan the file the admin screen already links to.
            // Checked per side, so a retry after the front finished does the back,
            // and before the source is looked for, so a finished sheet is returned
            // even when its source has since been cleaned away.
            $rows[] = $this->printFiles->forCustomDesignSide($customDesignId, $side)
                ?? $this->produceSide($design, $side, $this->sideSource($design, $side), $orderId);
        }

        return $rows;
    }

    /** The file a side's sheet is made from. */
    private function sideSource(array $design, string $side): string
    {
        if ($side !== 'back') {
            return $this->sourcePath($design);
        }

        if (!is_file((string) $design['back_original_path'])) {
            throw new RuntimeException('Back press file is missing for design ' . $design['id'] . '.');
        }

        return (string) $design['back_original_path'];
    }

    /** @return array<string,mixed> the print_files row written */
    private function produceSide(array $design, string $side, string $source, ?int $orderId): array
    {
        $customDesignId = (int) $design['id'];
        $stem = $this->stem($customDesignId) . ($side === 'single' ? '' : '-' . $side);
        $dir  = $this->directory($orderId);

        $printSize = Config::get('garments.tshirt.print_size_cm');
        $bleed     = (float) Config::get('print.bleed_mm', 3);
        $icc       = (string) (Config::get('print.icc_profile') ?? '');

        // Colour first: whatever the driver produces is what the PDF embeds.
        $converted = $dir . '/' . $stem . ($this->driver->convertsColour() ? '.jpg' : '.' . self::extension($source));
        $this->driver->toCmyk($source, $converted, $icc);

        $pdf = $dir . '/' . $stem . '.pdf';
        $this->driver->toPdf($converted, $pdf, (float) $printSize['w'], (float) $printSize['h'], $bleed);

        $preview = $dir . '/' . $stem . '-preview.png';
        $this->preview($source, $this->garmentColorFor($customDesignId), $preview);

        $row = [
            'custom_design_id' => $customDesignId,
            'design_id'        => null,
            'side'             => $side,
            'order_id'         => $orderId,
            'driver'           => $this->driverName(),
            'colour_space'     => $this->driver->convertsColour() ? 'CMYK' : 'RGB',
            'pdf_path'         => $this->relative($pdf),
            'preview_path'     => $this->relative($preview),
            'dpi'              => (int) $design['dpi'],
        ];

        $row['id'] = $this->printFiles->insert($row);

        return $row;
    }

    /**
     * The garment the agreement picture is drawn on.
     *
     * One artwork may go onto several colours in the same order. The preview is
     * a record of the artwork, so it takes the first line that ordered it and
     * says so; the press file itself is colour-independent.
     */
    private function garmentColorFor(int $customDesignId): string
    {
        $line = $this->orders->firstLineForCustomDesign($customDesignId);

        return $line['color_hex'] ?? '#EFEDE7';
    }

    /**
     * Flattened 800px record of what was agreed: the garment colour with the
     * artwork placed in the print area at its true proportions.
     *
     * GD is core on effectively every host and needs no exec.
     */
    public function preview(string $source, string $garmentColor, string $destination): void
    {
        $width   = 800;
        $ratio   = Config::get('garments.tshirt.print_size_cm');
        $height  = (int) round($width * ($ratio['h'] / $ratio['w']));
        $margin  = (int) round($width * 0.08);

        $canvas = imagecreatetruecolor($width, $height);
        [$r, $g, $b] = self::rgb($garmentColor);
        imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocate($canvas, $r, $g, $b));

        $area = [
            'x' => $margin,
            'y' => $margin,
            'w' => $width - 2 * $margin,
            'h' => $height - 2 * $margin,
        ];

        $art = self::loadRaster($source);

        if ($art === null) {
            // Vector source with no rasteriser on this host: mark the print area
            // rather than pretend the artwork was rendered.
            $ghost = imagecolorallocatealpha($canvas, 255, 255, 255, 90);
            imagefilledrectangle($canvas, $area['x'], $area['y'],
                $area['x'] + $area['w'], $area['y'] + $area['h'], $ghost);
        } else {
            $artW = imagesx($art);
            $artH = imagesy($art);
            $fit  = min($area['w'] / $artW, $area['h'] / $artH);
            $drawW = (int) round($artW * $fit);
            $drawH = (int) round($artH * $fit);

            imagecopyresampled(
                $canvas,
                $art,
                $area['x'] + (int) (($area['w'] - $drawW) / 2),
                $area['y'] + (int) (($area['h'] - $drawH) / 2),
                0,
                0,
                $drawW,
                $drawH,
                $artW,
                $artH
            );
            imagedestroy($art);
        }

        // Dashed outline of the print area, so the customer sees the boundary.
        $line = imagecolorallocate($canvas, 120, 120, 120);

        for ($x = $area['x']; $x < $area['x'] + $area['w']; $x += 12) {
            imageline($canvas, $x, $area['y'], min($x + 6, $area['x'] + $area['w']), $area['y'], $line);
            imageline($canvas, $x, $area['y'] + $area['h'], min($x + 6, $area['x'] + $area['w']), $area['y'] + $area['h'], $line);
        }

        for ($y = $area['y']; $y < $area['y'] + $area['h']; $y += 12) {
            imageline($canvas, $area['x'], $y, $area['x'], min($y + 6, $area['y'] + $area['h']), $line);
            imageline($canvas, $area['x'] + $area['w'], $y, $area['x'] + $area['w'], min($y + 6, $area['y'] + $area['h']), $line);
        }

        imagepng($canvas, $destination, 6);
        imagedestroy($canvas);
    }

    /** Resolves a stored relative path to somewhere inside storage/print. */
    public function absolutePath(string $relative): ?string
    {
        $base = Config::get('app.paths.print');
        $full = realpath($base . '/' . ltrim($relative, '/'));

        if ($full === false || !str_starts_with($full, (string) realpath($base))) {
            return null;
        }

        return $full;
    }

    private function sourcePath(array $design): string
    {
        // The untouched original when there is one, since the public copy of an
        // SVG has been stripped.
        if (!empty($design['original_path']) && is_file($design['original_path'])) {
            return $design['original_path'];
        }

        $path = Config::get('app.paths.public') . $design['image_path'];

        if (!is_file($path)) {
            throw new RuntimeException('Design file is missing: ' . $design['image_path']);
        }

        return $path;
    }

    /** Grouped by order so a production run is one folder to hand over. */
    private function directory(?int $orderId): string
    {
        $dir = Config::get('app.paths.print') . '/orders/' . ($orderId ?? 'loose');

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot write to ' . $dir);
        }

        return $dir;
    }

    private function relative(string $absolute): string
    {
        $base = Config::get('app.paths.print');

        return ltrim(str_replace('\\', '/', substr($absolute, strlen($base))), '/');
    }

    private function stem(int $customDesignId): string
    {
        return sprintf('design-%d', $customDesignId);
    }

    private static function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png';
    }

    /** @return \GdImage|null null when GD cannot decode the source */
    private static function loadRaster(string $path): ?\GdImage
    {
        $size = @getimagesize($path);

        if ($size === false) {
            return null;
        }

        $image = match ($size[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => false,
        };

        return $image === false ? null : $image;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
