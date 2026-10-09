<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Stores one uploaded image.
 *
 * The declared extension and the browser-supplied MIME type are both ignored:
 * the type comes from finfo and the stored name is generated, so a .php file
 * renamed to .jpg never reaches disk.
 *
 * SVG gets special handling. The original is written outside the web root and
 * never served; what lands under public/ is a sanitised copy.
 */
final class Uploader
{
    public function __construct(
        private readonly string $publicDirectory,
        private readonly string $privateDirectory,
        private readonly array $mimeTypes,
        private readonly int $maxBytes,
    ) {
    }

    public static function make(): self
    {
        return new self(
            Config::get('app.paths.uploads'),
            Config::get('app.paths.storage') . '/originals',
            Config::get('app.uploads.mime_types'),
            UploadLimit::bytes(),
        );
    }

    /**
     * @param array $file one entry of $_FILES
     * @return array{path:string,mime:string,width:int,height:int,bytes:int,original:?string}
     */
    public function store(array $file, string $bucket = 'designs'): array
    {
        $temp = $this->accept($file);
        $mime = $this->detect($temp);

        if ($mime === 'image/svg+xml') {
            return $this->storeSvg($temp, $bucket);
        }

        $size = @getimagesize($temp);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new UploadException('هذا الملف ليس صورة قابلة للقراءة.');
        }

        $name = $this->name($this->mimeTypes[$mime]);
        $this->place($temp, $this->publicDirectory . '/' . $bucket, $name, true);

        return [
            'path'     => '/uploads/' . $bucket . '/' . $name,
            'mime'     => $mime,
            'width'    => (int) $size[0],
            'height'   => (int) $size[1],
            'bytes'    => (int) filesize($this->publicDirectory . '/' . $bucket . '/' . $name),
            'original' => null,
        ];
    }

    private function storeSvg(string $temp, string $bucket): array
    {
        $raw = file_get_contents($temp);

        if ($raw === false) {
            throw new UploadException('تعذّر قراءة الملف.');
        }

        $clean = SvgSanitizer::clean($raw);
        $name  = $this->name('svg');

        // The untouched upload is evidence and print source; it stays where the
        // web server cannot reach it.
        $originalDirectory = $this->privateDirectory . '/' . $bucket;
        $this->ensure($originalDirectory);
        file_put_contents($originalDirectory . '/' . $name, $raw);

        $publicDirectory = $this->publicDirectory . '/' . $bucket;
        $this->ensure($publicDirectory);
        file_put_contents($publicDirectory . '/' . $name, $clean);
        chmod($publicDirectory . '/' . $name, 0644);
        unlink($temp);

        [$width, $height] = self::svgDimensions($clean);

        return [
            'path'     => '/uploads/' . $bucket . '/' . $name,
            'mime'     => 'image/svg+xml',
            'width'    => $width,
            'height'   => $height,
            'bytes'    => strlen($clean),
            'original' => $originalDirectory . '/' . $name,
        ];
    }

    private function accept(array $file): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error !== UPLOAD_ERR_OK) {
            throw new UploadException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => UploadLimit::message(),
                UPLOAD_ERR_NO_FILE                        => 'لم يصل أي ملف.',
                UPLOAD_ERR_PARTIAL                        => 'لم يكتمل الرفع.',
                default                                   => 'فشل الرفع.',
            });
        }

        $temp = (string) ($file['tmp_name'] ?? '');

        if ($temp === '' || !is_uploaded_file($temp)) {
            throw new UploadException('فشل الرفع.');
        }

        if (filesize($temp) > $this->maxBytes) {
            throw new UploadException(UploadLimit::message());
        }

        return $temp;
    }

    private function detect(string $temp): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new UploadException('تعذّر فحص نوع الملف على الخادم.');
        }

        $mime = finfo_file($finfo, $temp);
        finfo_close($finfo);

        // finfo reports plain XML for many valid SVG files.
        if (in_array($mime, ['text/xml', 'application/xml', 'text/plain'], true)
            && self::looksLikeSvg($temp)) {
            $mime = 'image/svg+xml';
        }

        if (!is_string($mime) || !isset($this->mimeTypes[$mime])) {
            throw new UploadException('الأنواع المقبولة: JPEG و PNG و WebP و SVG فقط.');
        }

        return $mime;
    }

    private function place(string $temp, string $directory, string $name, bool $uploaded): void
    {
        $this->ensure($directory);
        $target = $directory . '/' . $name;

        $moved = $uploaded ? move_uploaded_file($temp, $target) : rename($temp, $target);

        if (!$moved) {
            throw new UploadException('تعذّر تخزين الملف.');
        }

        chmod($target, 0644);
    }

    private function ensure(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new UploadException('مجلد الرفع غير قابل للكتابة.');
        }
    }

    private function name(string $extension): string
    {
        return bin2hex(random_bytes(16)) . '.' . $extension;
    }

    private static function looksLikeSvg(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 1024);

        return stripos($head, '<svg') !== false;
    }

    /** @return array{0:int,1:int} */
    private static function svgDimensions(string $svg): array
    {
        if (preg_match('/viewBox\s*=\s*"[\d.\-]+\s+[\d.\-]+\s+([\d.]+)\s+([\d.]+)"/i', $svg, $m)) {
            return [(int) round((float) $m[1]), (int) round((float) $m[2])];
        }

        $width  = preg_match('/\swidth\s*=\s*"([\d.]+)/i', $svg, $w) ? (int) round((float) $w[1]) : 0;
        $height = preg_match('/\sheight\s*=\s*"([\d.]+)/i', $svg, $h) ? (int) round((float) $h[1]) : 0;

        return [$width, $height];
    }
}
