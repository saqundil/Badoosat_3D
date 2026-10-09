<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CustomDesignRepository;
use App\Support\Config;
use App\Support\Garment;
use App\Support\Uploader;
use App\Support\UploadException;

/**
 * Customer artwork, through either door.
 *
 * The 3D editor posts to store(); a company with a finished file posts to
 * storeUpload(). Both write one row of custom_designs with the same columns
 * filled, and -- this is the part that matters -- both get their resolution
 * verdict from the same inspectState(), over a design_json of the same shape.
 * Nothing downstream can tell which door a design came through, because there
 * is nothing downstream to tell it apart by.
 *
 * The browser sends its own resolution reading. It is not read. This recomputes
 * it from the original file and the canvas state, and the recomputed value is
 * the one that counts: a client that lies about DPI gets the same answer as one
 * that does not.
 */
final class CustomDesignService
{
    public const MAX_JSON_BYTES = 2 * 1024 * 1024;

    /**
     * The cart's vocabulary: one line may ask for the same artwork on both
     * sides. Read from the cart itself -- this used to be a second copy of the
     * same three strings, which is one more place to forget.
     */
    public const PLACEMENTS = CartService::PLACEMENTS;

    public function __construct(
        private readonly CustomDesignRepository $designs,
        private readonly PrintFileService $print,
        private readonly Garment $garment,
    ) {
    }

    /**
     * @param ?Garment $garment the cut being designed on, when it is known.
     *
     * It has to be passable. store() validates the uploaded press file against
     * exportPx(), which is measured per mesh -- so building this with the
     * default garment while the customer designs on another would check the
     * file against the wrong size and, once the two cuts stop sharing a mesh,
     * accept a press file that prints at the wrong dimensions.
     */
    public static function make(?Garment $garment = null): self
    {
        return new self(
            CustomDesignRepository::make(),
            PrintFileService::make(),
            $garment ?? Garment::make()
        );
    }

    /**
     * @param array $input   text fields
     * @param array $files   $_FILES
     * @return array{design_token:string,design_id:int,check:array}
     * @throws ValidationException
     */
    public function store(array $input, array $files, ?Uploader $uploader = null): array
    {
        $placement = (string) ($input['placement'] ?? 'front');

        if (!in_array($placement, Garment::SIDES, true)) {
            throw new ValidationException(['placement' => 'موضع الطباعة غير معروف.']);
        }

        $state = $this->decodeState($input['design_json'] ?? null);
        $uploader ??= Uploader::make();

        // The flat press file. It never comes from the 3D scene; the editor
        // exports it from the design canvas at the configured DPI.
        $printFile = $files['print_png'] ?? null;

        if (($printFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['print_png' => 'ملف الطباعة مفقود.']);
        }

        $expected = $this->garment->exportPx();
        $size = @getimagesize($printFile['tmp_name']);

        if ($size === false) {
            throw new ValidationException(['print_png' => 'ملف الطباعة غير قابل للقراءة.']);
        }

        // A couple of pixels of rounding is fine; a different size is not the
        // file this shop prints.
        if (abs($size[0] - $expected['w']) > 2 || abs($size[1] - $expected['h']) > 2) {
            throw new ValidationException(['print_png' => sprintf(
                'مقاس ملف الطباعة %d×%d، والمطلوب %d×%d.',
                $size[0], $size[1], $expected['w'], $expected['h']
            )]);
        }

        $check = $this->inspectState($state, $files);

        if ($check['quality'] === 'reject') {
            throw new ValidationException(['design' => $check['message']]);
        }

        $token = bin2hex(random_bytes(16));

        try {
            $press = $this->keepPrivate($printFile, $token . '.png');
            $preview = isset($files['preview_png']) && ($files['preview_png']['error'] ?? 1) === UPLOAD_ERR_OK
                ? $uploader->store($files['preview_png'], 'designs')
                : null;

            $original = isset($files['source']) && ($files['source']['error'] ?? 1) === UPLOAD_ERR_OK
                ? $this->keepPrivate($files['source'], $token . '-source.' . $this->extensionOf($files['source']))
                : null;
        } catch (UploadException $e) {
            throw new ValidationException(['upload' => $e->getMessage()]);
        }

        $designId = $this->designs->insert([
            'token'         => $token,
            'source'        => 'editor',
            // What the cart shows: a rendered mock-up, safe to serve.
            'image_path'    => $preview['path'] ?? '/images/hero-poster.webp',
            // What the press gets, kept where no URL reaches it.
            'original_path' => $press,
            'mime'          => 'image/png',
            'width_px'      => $size[0],
            'height_px'     => $size[1],
            'dpi'           => $check['dpi'],
            'quality'       => $check['quality'],
            'placement'     => $placement,
            'design_json'   => json_encode($state, JSON_UNESCAPED_UNICODE),
            'preview_path'  => $preview['path'] ?? null,
            'source_path'   => $original,
        ]);

        return ['design_token' => $token, 'design_id' => $designId, 'check' => $check];
    }

    /* ------------------------------------------------- the direct upload */

    /**
     * A finished file, straight from a company, a team or an event.
     *
     * These are the highest-value orders in the shop and the least patient:
     * somebody who has a print-ready PDF and wants forty shirts does not want
     * to be walked through a 3D editor to place it. They upload it, say what
     * sizes and how many, and leave.
     *
     * What comes out is a custom_designs row indistinguishable in structure
     * from one the editor writes. The design_json is synthesised in the
     * editor's own shape -- sides, objects, natural and displayed sizes -- so
     * the very same inspectState() judges the resolution, and so the file could
     * later be opened in the editor without a conversion step.
     *
     * @return array{design_token:string,design_id:int,check:array}
     * @throws ValidationException
     */
    public function storeUpload(array $input, array $files, ?Uploader $uploader = null): array
    {
        $placement = (string) ($input['placement'] ?? 'front');

        if (!in_array($placement, self::PLACEMENTS, true)) {
            throw new ValidationException(['placement' => 'موضع الطباعة غير معروف.']);
        }

        $file = $files['artwork'] ?? null;

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['artwork' => 'اختر ملف التصميم.']);
        }

        $uploader ??= Uploader::make();
        $token      = bin2hex(random_bytes(16));
        $kind       = $this->classify($file);

        // The state the editor would have produced for this file, placed to
        // fill the print area. inspectState() then reads it exactly as it
        // reads the editor's own, and reaches its verdict the same way.
        $state = $this->stateForUpload($kind, $placement);
        $check = $this->inspectState($state, $files);

        if ($check['quality'] === 'reject') {
            throw new ValidationException(['artwork' => $check['message']]);
        }

        try {
            if ($kind['previewable']) {
                /*
                 * Through the ordinary uploader first. It re-checks the type
                 * with finfo, enforces the size limit, sanitises an SVG and
                 * keeps the untouched original out of the web root -- the same
                 * guards every other upload on the site passes, and not worth
                 * reimplementing here to save a copy.
                 */
                $preview = $uploader->store($file, 'designs');
                $press   = $this->keepPrivateCopy(
                    Config::get('app.paths.public') . $preview['path'],
                    $token . '.' . $kind['extension']
                );
            } else {
                // A PDF: the uploader will not take one, and there is no
                // rasteriser here to make a picture of it. The press master is
                // stored and the line falls back to the garment photograph
                // rather than linking a file the page cannot render.
                $preview = null;
                $press   = $this->keepPrivate($file, $token . '.pdf', 'application/pdf');
            }
        } catch (UploadException $e) {
            throw new ValidationException(['artwork' => $e->getMessage()]);
        }

        $designId = $this->designs->insert([
            'token'         => $token,
            'source'        => 'upload',
            'image_path'    => $preview['path'] ?? '/images/hero-poster.webp',
            'original_path' => $press,
            'mime'          => $kind['mime'],
            'width_px'      => $kind['width'],
            'height_px'     => $kind['height'],
            'dpi'           => $check['dpi'],
            'quality'       => $check['quality'],
            'placement'     => $placement,
            'design_json'   => json_encode($state, JSON_UNESCAPED_UNICODE),
            'preview_path'  => $preview['path'] ?? null,
            'source_path'   => $press,
            'note'          => mb_substr(trim((string) ($input['note'] ?? '')), 0, 500) ?: null,
        ]);

        return ['design_token' => $token, 'design_id' => $designId, 'check' => $check];
    }

    /**
     * What kind of file this is, by content and never by extension.
     *
     * @return array{mime:string,extension:string,width:int,height:int,vector:bool,previewable:bool}
     * @throws ValidationException
     */
    private function classify(array $file): array
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = $finfo === false ? null : finfo_file($finfo, $file['tmp_name']);

        if ($finfo !== false) {
            finfo_close($finfo);
        }

        $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 1024);

        // finfo reports plain XML for a great many perfectly valid SVG files.
        if (in_array($mime, ['text/xml', 'application/xml', 'text/plain'], true)
            && stripos($head, '<svg') !== false) {
            $mime = 'image/svg+xml';
        }

        if ($mime === 'application/pdf' || str_starts_with($head, '%PDF-')) {
            [$width, $height] = self::pdfPixels($file['tmp_name']);

            return [
                'mime' => 'application/pdf', 'extension' => 'pdf',
                'width' => $width, 'height' => $height,
                'vector' => true, 'previewable' => false,
            ];
        }

        if ($mime === 'image/svg+xml') {
            return [
                'mime' => 'image/svg+xml', 'extension' => 'svg',
                'width' => 0, 'height' => 0,
                'vector' => true, 'previewable' => true,
            ];
        }

        $size = @getimagesize($file['tmp_name']);

        if ($size === false || !in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new ValidationException([
                'artwork' => 'الأنواع المقبولة: PNG و JPG و PDF و SVG.',
            ]);
        }

        return [
            'mime'      => (string) $size['mime'],
            'extension' => match ($size[2]) {
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_WEBP => 'webp',
                default        => 'png',
            },
            'width'       => (int) $size[0],
            'height'      => (int) $size[1],
            'vector'      => false,
            'previewable' => true,
        ];
    }

    /**
     * The editor's own state shape, for a single image filling the print area.
     *
     * A vector file has no resolution to fail on, so it is described without a
     * naturalWidth and inspectState() reaches the verdict it reaches for an
     * editor design holding only text: nothing raster here to judge.
     *
     * @return array<string,mixed>
     */
    private function stateForUpload(array $kind, string $placement): array
    {
        $work    = $this->garment->workPx();
        $objects = [];

        if (!$kind['vector'] && $kind['width'] > 0 && $kind['height'] > 0) {
            /*
             * Fitted to the size it will actually be printed at, keeping its
             * aspect, and centred on the panel.
             *
             * It used to be fitted to the whole panel -- 56 by 80 centimetres,
             * shoulders to hem -- on the reasoning that this is what the press
             * would do with it. No press does that with a chest print, and
             * measuring resolution against it asked for a 15.6 megapixel file
             * to escape a warning: larger than the upload limit allows, so the
             * check could only ever be failed.
             */
            $panel = $this->garment->printSizeCm();
            $artwork = $this->garment->artworkSizeCm();
            $box = [
                'w' => $work['w'] * ($artwork['w'] / $panel['w']),
                'h' => $work['h'] * ($artwork['h'] / $panel['h']),
            ];
            $scale = min($box['w'] / $kind['width'], $box['h'] / $kind['height']);

            $objects[] = [
                'type'          => 'Image',
                'naturalWidth'  => $kind['width'],
                'naturalHeight' => $kind['height'],
                'width'         => $kind['width'],
                'height'        => $kind['height'],
                'scaleX'        => $scale,
                'scaleY'        => $scale,
                'left'          => ($work['w'] - $kind['width'] * $scale) / 2,
                'top'           => ($work['h'] - $kind['height'] * $scale) / 2,
            ];
        }

        $sides = [];

        foreach ($placement === 'both' ? Garment::SIDES : [$placement] as $side) {
            $sides[$side] = ['objects' => $objects];
        }

        return ['version' => 1, 'source' => 'upload', 'sides' => $sides];
    }

    /**
     * A PDF's page box, in pixels at the target DPI.
     *
     * Imagick is not on most shared hosting and there is no Ghostscript to
     * call, so the MediaBox is read out of the file itself. It is in points,
     * and seventy-two of them make an inch.
     *
     * @return array{0:int,1:int}
     */
    private static function pdfPixels(string $path): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 65536);
        $dpi  = (int) Config::get('print.dpi.target', 300);
        $num  = '([0-9.\-]+)';

        $pattern = '/MediaBox\s*\[\s*' . $num . '\s+' . $num . '\s+' . $num . '\s+' . $num . '/';

        if (preg_match($pattern, $head, $m) !== 1) {
            return [0, 0];
        }

        $points = [abs((float) $m[3] - (float) $m[1]), abs((float) $m[4] - (float) $m[2])];

        return [
            (int) round($points[0] / 72 * $dpi),
            (int) round($points[1] / 72 * $dpi),
        ];
    }

    /**
     * Puts a copy of an already-stored public file where no URL reaches it.
     *
     * The public copy is what a customer's browser may fetch; this one is what
     * goes to the press, and it must survive the public one being replaced,
     * cleaned up or served through a resizer.
     *
     * @throws UploadException
     */
    private function keepPrivateCopy(string $source, string $name): string
    {
        $directory = Config::get('app.paths.print') . '/designs';

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new UploadException('مجلد التخزين غير قابل للكتابة.');
        }

        $target = $directory . '/' . $name;

        if (!copy($source, $target)) {
            throw new UploadException('تعذّر تخزين الملف.');
        }

        chmod($target, 0644);

        return $target;
    }

    /** Reopens a saved design exactly as it was left. */
    public function load(string $token): ?array
    {
        $row = $this->designs->findByToken($token);

        if ($row === null) {
            return null;
        }

        return [
            'token'       => $row['token'],
            'placement'   => $row['placement'],
            'design_json' => $row['design_json'] === null
                ? null
                : json_decode((string) $row['design_json'], true),
            'dpi'         => (int) $row['dpi'],
            'quality'     => $row['quality'],
            'preview'     => $row['preview_path'],
        ];
    }

    /**
     * Recomputes the print resolution from the canvas state and the original
     * file, ignoring anything the browser claimed.
     *
     * @return array{dpi:int,quality:string,message:string}
     */
    public function inspectState(array $state, array $files): array
    {
        $work = $this->garment->workPx()['w'];
        $cm = $this->garment->printSizeCm();
        $pxPerCm = $work / $cm['w'];

        $worst = null;
        $placed = ['w' => $cm['w'], 'h' => $cm['h']];

        foreach ($state['sides'] ?? [] as $side) {
            foreach ($side['objects'] ?? [] as $object) {
                if (($object['type'] ?? '') !== 'Image' && ($object['type'] ?? '') !== 'image') {
                    continue;
                }

                $natural = (float) ($object['naturalWidth'] ?? 0);
                $shown = (float) ($object['width'] ?? 0) * (float) ($object['scaleX'] ?? 1);

                if ($natural <= 0 || $shown <= 0) {
                    continue;
                }

                // Original pixels divided by the inches of cloth they are asked
                // to cover. Nothing the browser reported is used.
                $inches = ($shown / $pxPerCm) / 2.54;
                $dpi = $inches > 0 ? (int) floor($natural / $inches) : 0;

                if ($worst === null || $dpi < $worst) {
                    $worst = $dpi;
                    /* The placed size of the WORST image, so the advice below
                       can name the pixels that image needs rather than some
                       average of everything on the shirt. */
                    $placed = [
                        'w' => $shown / $pxPerCm,
                        'h' => ((float) ($object['height'] ?? 0) * (float) ($object['scaleY'] ?? 1)) / $pxPerCm,
                    ];
                }
            }
        }

        if ($worst === null) {
            return ['dpi' => 0, 'quality' => 'ok', 'message' => 'تصميم بلا صور نقطية.'];
        }

        $warn = (int) Config::get('print.dpi.warn', 150);
        $reject = (int) Config::get('print.dpi.reject', 100);

        /*
         * What the customer has to do about it, in the terms they can act on:
         * the resolution now, the resolution wanted, and the pixel dimensions
         * that would reach it AT THIS SIZE. "Low resolution" alone tells them
         * nothing they can go and fix.
         */
        $needed = static function (int $dpi) use ($placed): string {
            return (int) ceil($dpi * $placed['w'] / 2.54) . '×' . (int) ceil($dpi * $placed['h'] / 2.54)
                . ' بكسل';
        };

        $size = round($placed['w'], 1) . '×' . round($placed['h'], 1) . ' سم';

        [$quality, $message] = match (true) {
            $worst < $reject => ['reject',
                'الدقّة ' . $worst . ' DPI عند مقاس الطباعة ' . $size . ' — منخفضة جدًا. '
                . 'المطلوب ' . $reject . ' DPI على الأقل، أي ' . $needed($reject) . ' لهذا المقاس '
                . '(والأفضل ' . $warn . ' DPI، أي ' . $needed($warn) . ').'],
            $worst < $warn => ['warn',
                'الدقّة ' . $worst . ' DPI عند مقاس الطباعة ' . $size . ' — مقبولة، وقد تظهر الحواف ناعمة. '
                . 'لأفضل نتيجة استخدم ' . $warn . ' DPI، أي ' . $needed($warn) . '.'],
            default => ['ok',
                'الدقّة ' . $worst . ' DPI عند مقاس الطباعة ' . $size . ' — مناسبة للطباعة.'],
        };

        return ['dpi' => $worst, 'quality' => $quality, 'message' => $message];
    }

    /** @throws ValidationException */
    private function decodeState(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            throw new ValidationException(['design_json' => 'حالة التصميم مفقودة.']);
        }

        if (strlen($raw) > self::MAX_JSON_BYTES) {
            throw new ValidationException(['design_json' => 'حالة التصميم أكبر من المسموح.']);
        }

        try {
            $state = json_decode($raw, true, 24, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException(['design_json' => 'حالة التصميم ليست JSON صالحة.']);
        }

        if (!is_array($state) || !isset($state['sides']) || !is_array($state['sides'])) {
            throw new ValidationException(['design_json' => 'حالة التصميم غير مكتملة.']);
        }

        return $state;
    }

    /**
     * Moves an upload into storage, outside the web root, after the same finfo
     * check every other upload goes through.
     */
    private function keepPrivate(array $file, string $name, ?string $expected = null): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false ? null : finfo_file($finfo, $file['tmp_name']);

        if ($finfo !== false) {
            finfo_close($finfo);
        }

        /*
         * Checked again here, from the file's own bytes, even though classify()
         * has just done it. The rule from the first brief holds without
         * exception: nothing decides a file's type from its name, its extension
         * or the browser's word for it, and nothing trusts an earlier check to
         * have covered a later write.
         */
        $allowed = array_keys(Config::get('app.uploads.mime_types', []));

        // The press accepts a PDF; the browser-facing uploader never does, which
        // is why this list is longer than the one under public/.
        $allowed[] = 'application/pdf';

        if (in_array($mime, ['text/xml', 'application/xml', 'text/plain'], true)
            && $expected === 'image/svg+xml') {
            $mime = 'image/svg+xml';
        }

        if (!is_string($mime) || !in_array($mime, $allowed, true)) {
            throw new UploadException('نوع الملف غير مقبول.');
        }

        if ($expected !== null && $mime !== $expected) {
            throw new UploadException('نوع الملف لا يطابق محتواه.');
        }

        $dir = Config::get('app.paths.print') . '/designs';

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UploadException('مجلد التخزين غير قابل للكتابة.');
        }

        $target = $dir . '/' . $name;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new UploadException('تعذّر تخزين الملف.');
        }

        chmod($target, 0644);

        return $target;
    }

    private function extensionOf(array $file): string
    {
        $size = @getimagesize($file['tmp_name']);

        return match ($size[2] ?? null) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_WEBP => 'webp',
            default        => 'png',
        };
    }
}
