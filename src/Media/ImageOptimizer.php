<?php

namespace Nevela\Laravel\Media;

use GdImage;
use RuntimeException;

/**
 * Turns an uploaded image into the files worth storing: one that fits the profile's box,
 * and the profile's named renditions. Pure PHP and GD: no Laravel, no filesystem writes.
 *
 * What it does to every image, following Grit's pipeline:
 *
 * - Turns it the right way up. A phone stores a portrait photo sideways with a note saying
 *   so (EXIF orientation); the note is applied to the pixels, then dropped with the rest of
 *   the metadata, which also removes the location the photo was taken at.
 * - Scales it down to fit, never up.
 * - Picks the format from the pixels when the profile says "auto": transparency is kept
 *   (lossless WebP), and everything else becomes lossy WebP, a quarter smaller than a JPEG
 *   that looks the same. Encoding a transparent logo as JPEG, and giving it a black box,
 *   can't happen.
 * - Refuses an image that would decode to more pixels than the profile allows, from its
 *   header, before any memory is spent on it.
 */
final class ImageOptimizer
{
    /** The types worth optimising. GIF is left alone: decoding one keeps only its first frame. */
    public const OPTIMISABLE = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagecopyresampled');
    }

    /** Whether this build of PHP can write a format. */
    public static function writes(string $format): bool
    {
        return match ($format) {
            'webp' => function_exists('imagewebp'),
            'avif' => function_exists('imageavif'),
            'jpeg' => function_exists('imagejpeg'),
            'png' => function_exists('imagepng'),
            default => false,
        };
    }

    /**
     * @return array{
     *     primary: array{bytes: string, width: int, height: int, mime: string, ext: string},
     *     renditions: array<string, array{bytes: string, width: int, height: int, mime: string, ext: string}>,
     *     originalWidth: int,
     *     originalHeight: int,
     * }
     *
     * @throws RuntimeException when the image can't be read, or is too large to decode safely
     */
    public static function transform(string $bytes, Profile $profile): array
    {
        if (! self::available()) {
            throw new RuntimeException('PHP\'s GD extension is not installed, so images are stored as they are uploaded.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw new RuntimeException('The file is not an image PHP can read.');
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width * $height > $profile->maxPixels) {
            throw new RuntimeException("The image is {$width}×{$height}, which is more than the ".number_format($profile->maxPixels).' pixels this field allows.');
        }

        $source = @imagecreatefromstring($bytes);
        if (! $source instanceof GdImage) {
            // An animated WebP lands here: GD reads still images only.
            throw new RuntimeException('The image could not be decoded.');
        }
        if (! imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }
        $source = self::upright($source, $bytes, $info['mime'] ?? '');
        $width = imagesx($source);
        $height = imagesy($source);

        $transparent = ($info['mime'] ?? '') !== 'image/jpeg' && self::hasTransparency($source);
        $format = self::format($profile->format, $transparent);

        $primary = self::render($source, $profile->max, $format, $profile->quality, $transparent);

        // A flat graphic (a diagram, a screenshot) is what PNG is good at: as a PNG it can be
        // smaller than any lossy version of itself. So a PNG is also written as one, and the
        // smaller result is kept, with its renditions in the same format so they sit beside
        // it. It is written again, never copied: a PNG can carry text and location data in
        // its metadata, and writing it from the pixels is what leaves that behind.
        if (($info['mime'] ?? '') === 'image/png' && $profile->format === 'auto' && $format !== 'png' && self::writes('png')) {
            $png = self::render($source, $profile->max, 'png', $profile->quality, $transparent);
            if (strlen($png['bytes']) < strlen($primary['bytes'])) {
                $format = 'png';
                $primary = $png;
            }
        }

        $result = [
            'primary' => $primary,
            'renditions' => [],
            'originalWidth' => $width,
            'originalHeight' => $height,
        ];
        foreach ($profile->renditions as $name => $size) {
            $result['renditions'][$name] = self::render($source, $size, $format, $profile->quality, $transparent);
        }

        return $result;
    }

    /**
     * What "auto" means for this image, and what to fall back to when PHP can't write the
     * format that was asked for.
     */
    public static function format(string $wanted, bool $transparent): string
    {
        if ($wanted !== 'auto' && self::writes($wanted)) {
            return $wanted;
        }
        if (self::writes('webp')) {
            return 'webp';
        }

        return $transparent ? 'png' : 'jpeg';
    }

    /** Apply the EXIF orientation of a JPEG to its pixels. */
    private static function upright(GdImage $image, string $bytes, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        // imagerotate turns anticlockwise, so 90° clockwise is -90.
        $turn = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($turn !== 0) {
            $rotated = imagerotate($image, $turn, 0);
            if ($rotated instanceof GdImage) {
                $image = $rotated;
            }
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    /**
     * Whether any part of the image is see-through enough to matter. Checked on a small
     * copy: reading twelve million pixels one by one in PHP takes seconds, and averaging
     * them down first finds every transparent area a person could see.
     */
    private static function hasTransparency(GdImage $image): bool
    {
        $side = 48;
        $small = imagecreatetruecolor($side, $side);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $side, $side, imagesx($image), imagesy($image));
        for ($y = 0; $y < $side; $y++) {
            for ($x = 0; $x < $side; $x++) {
                // The top 7 bits are alpha: 0 is solid, 127 is fully transparent.
                if (((imagecolorat($small, $x, $y) >> 24) & 0x7F) > 2) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{0: int, 1: int, 2: bool}  $size
     * @return array{bytes: string, width: int, height: int, mime: string, ext: string}
     */
    private static function render(GdImage $source, array $size, string $format, int $quality, bool $transparent): array
    {
        [$boxWidth, $boxHeight, $crop] = $size;
        $width = imagesx($source);
        $height = imagesy($source);
        $fromX = $fromY = 0;
        $fromWidth = $width;
        $fromHeight = $height;

        if ($crop) {
            // Take the largest centred area with the box's shape, then scale that down.
            $ratio = $boxWidth / $boxHeight;
            if ($width / $height > $ratio) {
                $fromWidth = max(1, (int) round($height * $ratio));
                $fromX = intdiv($width - $fromWidth, 2);
            } else {
                $fromHeight = max(1, (int) round($width / $ratio));
                $fromY = intdiv($height - $fromHeight, 2);
            }
        }
        $scale = min(1, $boxWidth / $fromWidth, $boxHeight / $fromHeight);
        $toWidth = max(1, (int) round($fromWidth * $scale));
        $toHeight = max(1, (int) round($fromHeight * $scale));

        $keepAlpha = $transparent && $format !== 'jpeg';
        $target = imagecreatetruecolor($toWidth, $toHeight);
        if ($keepAlpha) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        } else {
            // Anything see-through is put on white, not on the black a JPEG would give it.
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        }
        imagecopyresampled($target, $source, 0, 0, $fromX, $fromY, $toWidth, $toHeight, $fromWidth, $fromHeight);

        ob_start();
        $written = match ($format) {
            // Lossless when there is transparency to keep: a logo's edges don't survive lossy.
            'webp' => imagewebp($target, null, $keepAlpha && defined('IMG_WEBP_LOSSLESS') ? IMG_WEBP_LOSSLESS : $quality),
            'avif' => imageavif($target, null, $quality),
            'png' => imagepng($target, null, 9),
            default => self::jpeg($target, $quality),
        };
        $bytes = (string) ob_get_clean();
        if (! $written || $bytes === '') {
            throw new RuntimeException("The image could not be written as {$format}.");
        }

        return [
            'bytes' => $bytes,
            'width' => $toWidth,
            'height' => $toHeight,
            'mime' => 'image/'.$format,
            'ext' => $format === 'jpeg' ? 'jpg' : $format,
        ];
    }

    private static function jpeg(GdImage $image, int $quality): bool
    {
        // Progressive: the picture appears whole and sharpens, instead of arriving top to bottom.
        imageinterlace($image, true);

        return imagejpeg($image, null, $quality);
    }
}
