<?php

namespace Nevela\Laravel\Media;

/**
 * How one image field's uploads are optimised. The idea and the numbers are Grit's.
 *
 * Every default is one somebody would otherwise have to research:
 *
 *   1600  covers a 2x display at a typical content width. Storing more keeps pixels no
 *         browser will draw.
 *   82    the quality at which a lossy image is indistinguishable from its source. Below
 *         about 75, gradients and skin start to show artefacts.
 *   400   the thumbnail a table row or a grid of cards needs at 2x.
 *
 * Profiles are plain arrays in config/nevela.php, under uploads.profiles. A named profile
 * only says what differs from the default.
 */
final class Profile
{
    public const FORMATS = ['auto', 'webp', 'jpeg', 'png', 'avif'];

    public const DEFAULTS = [
        'max' => [1600, 1600],
        'quality' => 82,
        'format' => 'auto',
        'renditions' => ['thumb' => [400, 400, 'crop']],
        'keep_original' => true,
        'on_error' => 'store_original',
        'max_pixels' => 50_000_000,
    ];

    /**
     * @param  array{0: int, 1: int, 2: bool}  $max  The box the stored image fits inside: width, height, crop to fill
     * @param  int  $quality  1 to 100, for lossy formats
     * @param  string  $format  One of FORMATS. "auto" keeps transparency and otherwise picks the smallest
     * @param  array<string, array{0: int, 1: int, 2: bool}>  $renditions  Extra sizes made alongside, by name
     * @param  bool  $keepOriginal  Whether the untouched upload is kept, privately, for reprocessing
     * @param  bool  $reject  Whether an image that can't be optimised is refused, rather than stored as it came
     * @param  int  $maxPixels  Refuse anything that would decode to more than this (a decompression bomb is small on disk)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $max,
        public readonly int $quality,
        public readonly string $format,
        public readonly array $renditions,
        public readonly bool $keepOriginal,
        public readonly bool $reject,
        public readonly int $maxPixels,
    ) {}

    /**
     * @param  array<string, mixed>  $options  What this profile sets
     * @param  array<string, mixed>  $defaults  What it falls back to: the app's "default" profile
     */
    public static function fromArray(string $name, array $options, array $defaults = []): self
    {
        $o = $options + $defaults + self::DEFAULTS;
        $renditions = [];
        foreach ((array) $o['renditions'] as $key => $size) {
            if (preg_match('/^[a-z][a-z0-9-]*$/', (string) $key)) {
                $renditions[(string) $key] = self::size((array) $size);
            }
        }
        $quality = (int) $o['quality'];

        return new self(
            $name,
            self::size((array) $o['max']),
            $quality >= 1 && $quality <= 100 ? $quality : self::DEFAULTS['quality'],
            in_array($o['format'], self::FORMATS, true) ? $o['format'] : 'auto',
            $renditions,
            (bool) $o['keep_original'],
            $o['on_error'] === 'reject',
            max(1, (int) $o['max_pixels']),
        );
    }

    /**
     * [width, height] fits inside the box; a third value of "crop" (or true) fills it exactly.
     *
     * @param  array<int, mixed>  $size
     * @return array{0: int, 1: int, 2: bool}
     */
    private static function size(array $size): array
    {
        $size = array_values($size);
        $width = max(1, (int) ($size[0] ?? self::DEFAULTS['max'][0]));
        $height = max(1, (int) ($size[1] ?? $width));

        return [$width, $height, in_array($size[2] ?? false, ['crop', 'fill', true], true)];
    }
}
