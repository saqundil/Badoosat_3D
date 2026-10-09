<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Responsive images without a manifest.
 *
 * tools/make-images.php writes three widths beside every source it is given,
 * named `<base>-400.webp`, `-800.webp` and `-1200.webp`. This class states that
 * convention once so no template has to know it, and falls back to the single
 * original when the derivatives are missing -- a listing page that renders
 * nothing because an image was not processed is a far worse failure than one
 * that serves a picture larger than it needs.
 */
final class Image
{
    public const WIDTHS = [400, 800, 1200];
    public const THUMB_WIDTH = 160;

    /** @return array{src:string,srcset:string,sizes:string} */
    public static function responsive(string $path, string $sizes): array
    {
        $available = self::available($path);

        if ($available === []) {
            return ['src' => $path, 'srcset' => '', 'sizes' => ''];
        }

        $srcset = [];

        foreach ($available as $width => $url) {
            $srcset[] = $url . ' ' . $width . 'w';
        }

        return [
            // The middle width is the src, so a browser that ignores srcset
            // entirely still gets something sensible rather than the largest.
            'src'    => $available[800] ?? end($available),
            'srcset' => implode(', ', $srcset),
            'sizes'  => $sizes,
        ];
    }

    /** @return array<int,string> width => URL, only for files that exist */
    public static function available(string $path): array
    {
        $public = (string) Config::get('app.paths.public');
        $found  = [];

        foreach (self::WIDTHS as $width) {
            $candidate = self::variant($path, $width);

            if (is_file($public . $candidate)) {
                $found[$width] = $candidate;
            }
        }

        return $found;
    }

    public static function variant(string $path, int $width): string
    {
        $dot = strrpos($path, '.');

        if ($dot === false) {
            return $path . '-' . $width . '.webp';
        }

        return substr($path, 0, $dot) . '-' . $width . '.webp';
    }

    public static function thumbnail(string $path): string
    {
        $dot = strrpos($path, '.');

        return ($dot === false ? $path : substr($path, 0, $dot)) . '-thumb.webp';
    }

    /** @return array<int|string,string> generated public URLs */
    public static function generate(string $path): array
    {
        $public = (string) Config::get('app.paths.public');
        $source = $public . $path;

        if (!is_file($source)) {
            throw new UploadException('تعذّر العثور على الصورة المرفوعة لمعالجتها.');
        }

        if (extension_loaded('gd') && function_exists('imagewebp')) {
            return self::generateWithGd($source, $path);
        }

        if (extension_loaded('imagick')) {
            return self::generateWithImagick($source, $path);
        }

        throw new UploadException('معالجة صور المنتجات تتطلب امتداد GD أو Imagick على الخادم.');
    }

    /** @return array<int|string,string> */
    private static function generateWithGd(string $source, string $path): array
    {
        $raw = file_get_contents($source);
        $image = $raw === false ? false : @imagecreatefromstring($raw);

        if ($image === false) {
            throw new UploadException('تعذّر فتح الصورة عبر GD. استخدم JPEG أو PNG أو WebP صالحًا.');
        }

        $generated = [];

        try {
            foreach ([...self::WIDTHS, self::THUMB_WIDTH] as $width) {
                $url = $width === self::THUMB_WIDTH ? self::thumbnail($path) : self::variant($path, $width);
                self::writeGd($image, (string) Config::get('app.paths.public') . $url, $width);
                $generated[$width === self::THUMB_WIDTH ? 'thumbnail' : $width] = $url;
            }
        } finally {
            imagedestroy($image);
        }

        return $generated;
    }

    private static function writeGd(\GdImage $source, string $target, int $requestedWidth): void
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $targetWidth = min($requestedWidth, $width);
        $targetHeight = max(1, (int) round($height * ($targetWidth / $width)));
        $output = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($output, false);
        imagesavealpha($output, true);
        imagecopyresampled($output, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        if (!imagewebp($output, $target, 84)) {
            imagedestroy($output);
            throw new UploadException('تعذّر إنشاء نسخة WebP للصورة.');
        }

        imagedestroy($output);
        chmod($target, 0644);
    }

    /** @return array<int|string,string> */
    private static function generateWithImagick(string $source, string $path): array
    {
        try {
            $imagick = 'Imagick';
            $image = new $imagick($source);
            $image->setIteratorIndex(0);
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(84);
            $generated = [];

            foreach ([...self::WIDTHS, self::THUMB_WIDTH] as $width) {
                $copy = clone $image;
                $copy->thumbnailImage(min($width, $copy->getImageWidth()), 0);
                $url = $width === self::THUMB_WIDTH ? self::thumbnail($path) : self::variant($path, $width);

                if (!$copy->writeImage((string) Config::get('app.paths.public') . $url)) {
                    throw new UploadException('تعذّر إنشاء نسخة WebP للصورة.');
                }

                $copy->clear();
                $generated[$width === self::THUMB_WIDTH ? 'thumbnail' : $width] = $url;
            }

            $image->clear();

            return $generated;
        } catch (\Throwable $error) {
            throw new UploadException('تعذّر فتح الصورة عبر Imagick: ' . $error->getMessage());
        }
    }
}
