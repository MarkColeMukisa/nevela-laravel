<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Media\FileTypes;
use Nevela\Laravel\Media\ImageOptimizer;
use Nevela\Laravel\Media\Profile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MediaTest extends TestCase
{
    protected function setUp(): void
    {
        if (! ImageOptimizer::available()) {
            $this->markTestSkipped('PHP\'s GD extension is not installed.');
        }
    }

    /** A picture with enough detail that it doesn't compress to nothing. */
    private function photo(int $width, int $height, string $as = 'jpeg'): string
    {
        $image = imagecreatetruecolor($width, $height);
        mt_srand(7);
        for ($i = 0; $i < 400; $i++) {
            imagefilledellipse($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(10, 200), mt_rand(10, 200), imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
        mt_srand();
        ob_start();
        $as === 'png' ? imagepng($image) : imagejpeg($image, null, 92);

        return (string) ob_get_clean();
    }

    private function logo(): string
    {
        $image = imagecreatetruecolor(600, 300);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, 300, 150, 400, 200, imagecolorallocatealpha($image, 111, 0, 255, 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public function test_a_named_profile_only_says_what_differs_from_the_default(): void
    {
        $default = Profile::fromArray('default', []);
        $this->assertSame([[1600, 1600, false], 82, 'auto', ['thumb' => [400, 400, true]], true, false], [$default->max, $default->quality, $default->format, $default->renditions, $default->keepOriginal, $default->reject]);

        $product = Profile::fromArray('product', ['max' => [1000, 1000], 'renditions' => ['thumb' => [300, 300, 'crop'], 'card' => [600, 600]]], ['quality' => 70]);
        $this->assertSame([[1000, 1000, false], 70, ['thumb' => [300, 300, true], 'card' => [600, 600, false]]], [$product->max, $product->quality, $product->renditions]);

        // Values that can't be right fall back instead of producing a broken image.
        $odd = Profile::fromArray('odd', ['quality' => 900, 'format' => 'tiff', 'on_error' => 'reject', 'renditions' => ['Bad Name' => [10, 10]]]);
        $this->assertSame([82, 'auto', true, []], [$odd->quality, $odd->format, $odd->reject, $odd->renditions]);
    }

    public function test_a_photo_is_scaled_to_fit_and_its_renditions_are_made_alongside(): void
    {
        $bytes = $this->photo(2400, 1600);
        $profile = Profile::fromArray('product', ['max' => [1000, 1000], 'renditions' => ['thumb' => [300, 300, 'crop'], 'card' => [600, 600]]]);
        $result = ImageOptimizer::transform($bytes, $profile);

        $this->assertSame([2400, 1600], [$result['originalWidth'], $result['originalHeight']]);
        // Fit keeps the shape; crop fills the box exactly.
        $this->assertSame([1000, 667], [$result['primary']['width'], $result['primary']['height']]);
        $this->assertSame([300, 300], [$result['renditions']['thumb']['width'], $result['renditions']['thumb']['height']]);
        $this->assertSame([600, 400], [$result['renditions']['card']['width'], $result['renditions']['card']['height']]);
        $this->assertLessThan(strlen($bytes) / 2, strlen($result['primary']['bytes']));

        // What was written is what it says it is.
        $written = getimagesizefromstring($result['primary']['bytes']);
        $this->assertSame([1000, 667, $result['primary']['mime']], [$written[0], $written[1], $written['mime']]);
        $this->assertSame(ImageOptimizer::writes('webp') ? 'webp' : 'jpg', $result['primary']['ext']);
    }

    public function test_an_image_is_never_scaled_up(): void
    {
        $result = ImageOptimizer::transform($this->photo(320, 200), Profile::fromArray('default', []));

        $this->assertSame([320, 200], [$result['primary']['width'], $result['primary']['height']]);
        // A crop box larger than the image takes the box's shape at the image's size.
        $this->assertSame([200, 200], [$result['renditions']['thumb']['width'], $result['renditions']['thumb']['height']]);
    }

    public function test_transparency_is_kept_and_never_turned_into_a_black_box(): void
    {
        $result = ImageOptimizer::transform($this->logo(), Profile::fromArray('default', []));
        $this->assertContains($result['primary']['mime'], ['image/webp', 'image/png']);

        $decoded = imagecreatefromstring($result['primary']['bytes']);
        $corner = (imagecolorat($decoded, 2, 2) >> 24) & 0x7F;
        $this->assertGreaterThan(120, $corner, 'the corner should still be transparent');

        // Asked for JPEG, which has no transparency: the see-through part becomes white.
        $jpeg = ImageOptimizer::transform($this->logo(), Profile::fromArray('flat', ['format' => 'jpeg']));
        $this->assertSame('image/jpeg', $jpeg['primary']['mime']);
        $rgb = imagecolorat(imagecreatefromstring($jpeg['primary']['bytes']), 2, 2);
        $this->assertGreaterThan(240, ($rgb >> 16) & 0xFF);
        $this->assertGreaterThan(240, $rgb & 0xFF);
    }

    public function test_a_photograph_saved_as_png_is_not_stored_losslessly(): void
    {
        // Noise, as a camera sensor produces: what PNG is worst at.
        $image = imagecreatetruecolor(500, 300);
        mt_srand(11);
        for ($y = 0; $y < 300; $y++) {
            for ($x = 0; $x < 500; $x++) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, ($x + mt_rand(0, 60)) % 256, ($y + mt_rand(0, 60)) % 256, mt_rand(90, 150)));
            }
        }
        mt_srand();
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $result = ImageOptimizer::transform($png, Profile::fromArray('default', []));

        $this->assertNotSame('image/png', $result['primary']['mime']);
        $this->assertLessThan(strlen($png) / 2, strlen($result['primary']['bytes']));
    }

    public function test_a_flat_graphic_already_smaller_than_any_re_encoding_is_kept_as_it_is(): void
    {
        $png = $this->photo(800, 500, 'png');
        $result = ImageOptimizer::transform($png, Profile::fromArray('default', []));

        if ($result['primary']['mime'] === 'image/png') {
            // Kept byte for byte, with its thumbnail beside it in the same format.
            $this->assertSame($png, $result['primary']['bytes']);
            $this->assertSame('png', $result['renditions']['thumb']['ext']);
        } else {
            $this->assertLessThan(strlen($png), strlen($result['primary']['bytes']));
        }
        $this->assertSame($result['primary']['ext'], $result['renditions']['thumb']['ext']);
    }

    public function test_an_image_with_too_many_pixels_is_refused_before_it_is_decoded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pixels this field allows');

        ImageOptimizer::transform($this->photo(400, 400), Profile::fromArray('tiny', ['max_pixels' => 10_000]));
    }

    public function test_something_that_is_not_an_image_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        ImageOptimizer::transform('this is plain text, not a picture', Profile::fromArray('default', []));
    }

    public function test_a_field_accepts_only_its_own_kinds_of_file_and_never_one_a_browser_would_run(): void
    {
        $this->assertTrue(FileTypes::accepts(['image'], 'image/webp'));
        $this->assertFalse(FileTypes::accepts(['image'], 'application/pdf'));
        $this->assertTrue(FileTypes::accepts(['pdf', 'video'], 'video/mp4'));
        $this->assertTrue(FileTypes::accepts(['any'], 'application/zip'));
        $this->assertTrue(FileTypes::accepts(['image'], 'image/png; charset=binary'));
        foreach (['text/html', 'image/svg+xml', 'application/javascript', 'application/x-dosexec', ''] as $never) {
            $this->assertFalse(FileTypes::accepts(['any'], $never), $never);
        }

        $this->assertSame(['jpg', 'bin', 'bin', 'bin', 'pdf'], array_map(FileTypes::safeExtension(...), ['Photo.JPG', 'page.html', 'shell.php', 'no-extension', 'manual.v2.pdf']));
    }

    public function test_what_a_file_was_sent_as_has_to_agree_with_what_it_contains(): void
    {
        $this->assertTrue(FileTypes::consistent('image/jpeg', 'image/jpeg'));
        // A PNG sent as a JPEG is still an image; text sent as one is not.
        $this->assertTrue(FileTypes::consistent('image/jpeg', 'image/png'));
        $this->assertFalse(FileTypes::consistent('image/png', 'text/plain'));
        $this->assertFalse(FileTypes::consistent('application/pdf', 'application/zip'));
        // A .docx is a zip underneath, and a CSV is plain text.
        $this->assertTrue(FileTypes::consistent('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'));
        $this->assertTrue(FileTypes::consistent('text/csv', 'text/plain'));
        $this->assertFalse(FileTypes::consistent('application/octet-stream', 'text/html'));
    }
}
