<?php

namespace Nevela\Laravel\Media;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nevela\Laravel\Models\Upload;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use RuntimeException;
use Throwable;

/**
 * Stores uploaded files and answers questions about them.
 *
 * A record's file field holds a key such as
 * `products/image/2026/10/<uuid>-kettle.webp`. An image's renditions sit beside it with
 * their name before the extension (`…-kettle.thumb.webp`), so a client that knows the key
 * can ask for a size without looking anything up. The untouched original is kept on a
 * private disk, so the image can be optimised again later with different settings.
 */
final class Uploads
{
    /** @var array<string, Upload|null> Rows already looked up in this request, by key. */
    private static array $loaded = [];

    public static function disk(): string
    {
        return (string) config('nevela.uploads.disk', 'public');
    }

    public static function maxBytes(): int
    {
        return (int) config('nevela.uploads.max_bytes', 10 * 1024 * 1024);
    }

    /** The profile a field names, or the default when it names none or one that isn't defined. */
    public static function profile(?string $name): Profile
    {
        $profiles = (array) config('nevela.uploads.profiles', []);
        $default = (array) ($profiles['default'] ?? []);
        if ($name === null || $name === 'default' || ! isset($profiles[$name])) {
            return Profile::fromArray('default', $default);
        }

        return Profile::fromArray($name, (array) $profiles[$name], $default);
    }

    /**
     * Store one upload, optimising it first when it is an image for an image field.
     *
     * @param  string  $name  The file's name as the person had it
     * @param  string  $path  Where the uploaded bytes are on this machine
     * @param  string  $mime  What the file is, from its contents
     *
     * @throws UploadRejected when the field's profile refuses images it can't optimise
     */
    public static function store(Descriptor $resource, Field $field, string $name, string $path, string $mime, ?string $uploadedBy = null): Upload
    {
        $disk = Storage::disk(self::disk());
        $extension = FileTypes::safeExtension($name);
        $slug = Str::limit(Str::slug(pathinfo($name, PATHINFO_FILENAME)) ?: 'file', 60, '');
        $base = sprintf('%s/%s/%s/%s-%s', $resource->table, $field->name, date('Y/m'), (string) Str::uuid(), $slug);
        $bytes = (string) file_get_contents($path);

        $row = [
            'disk' => self::disk(),
            'resource' => $resource->name,
            'field' => $field->name,
            'name' => Str::limit($name, 200, ''),
            'uploaded_by' => $uploadedBy,
        ];
        $profile = self::profile($field->profile);

        if ($field->isImage() && in_array($mime, ImageOptimizer::OPTIMISABLE, true)) {
            $written = [];
            $originals = Storage::disk((string) config('nevela.uploads.originals_disk', 'local'));
            $originalKey = null;
            try {
                self::makeRoom();
                $result = ImageOptimizer::transform($bytes, $profile);
                $primary = $result['primary'];
                $key = "{$base}.{$primary['ext']}";
                self::write($disk, $written[] = $key, $primary['bytes'], 'public');

                $renditions = [];
                foreach ($result['renditions'] as $rendition => $image) {
                    $renditionKey = "{$base}.{$rendition}.{$image['ext']}";
                    self::write($disk, $written[] = $renditionKey, $image['bytes'], 'public');
                    $renditions[$rendition] = ['key' => $renditionKey, 'width' => $image['width'], 'height' => $image['height'], 'size' => strlen($image['bytes'])];
                }

                if ($profile->keepOriginal) {
                    $originalKey = "nevela/originals/{$base}.{$extension}";
                    self::write($originals, $originalKey, $bytes);
                }

                return self::remember(Upload::create($row + [
                    'key' => $key,
                    'mime' => $primary['mime'],
                    'size' => strlen($primary['bytes']),
                    'width' => $primary['width'],
                    'height' => $primary['height'],
                    'renditions' => $renditions,
                    'profile' => $profile->name,
                    'optimised' => true,
                    'original_key' => $originalKey,
                    'original_size' => strlen($bytes),
                ]));
            } catch (Throwable $e) {
                // Whatever was written before it failed is taken away again: no half-made sets.
                $disk->delete($written);
                if ($originalKey !== null) {
                    $originals->delete($originalKey);
                }
                if ($profile->reject) {
                    throw new UploadRejected($e->getMessage(), previous: $e);
                }
                // Losing somebody's file because an encoder choked is worse than storing a
                // large one. It is kept as it came and marked, so it can be found later.
                report($e);
            }
        }

        $key = "{$base}.{$extension}";
        self::write($disk, $key, $bytes, 'public');
        $size = str_starts_with($mime, 'image/') ? @getimagesizefromstring($bytes) : false;

        return self::remember(Upload::create($row + [
            'key' => $key,
            'mime' => $mime,
            'size' => strlen($bytes),
            'width' => $size ? $size[0] : null,
            'height' => $size ? $size[1] : null,
            'profile' => $field->isImage() ? $profile->name : null,
            'optimised' => false,
        ]));
    }

    /** The address a browser can fetch a stored file from. */
    public static function url(string $key): string
    {
        $base = config('nevela.uploads.url');
        if (is_string($base) && $base !== '') {
            return rtrim($base, '/').'/'.ltrim($key, '/');
        }

        return route('nevela.files.show', ['path' => $key]);
    }

    /**
     * Look up many keys in one query, so a page of records with images costs one extra
     * query and not one per record.
     *
     * @param  iterable<string|null>  $keys
     */
    public static function preload(iterable $keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if (is_string($key) && $key !== '' && ! array_key_exists($key, self::$loaded)) {
                $missing[$key] = true;
            }
        }
        if ($missing === []) {
            return;
        }
        foreach (array_keys($missing) as $key) {
            self::$loaded[$key] = null;
        }
        foreach (Upload::query()->whereIn('key', array_keys($missing))->get() as $upload) {
            self::$loaded[$upload->key] = $upload;
        }
    }

    public static function find(string $key): ?Upload
    {
        self::preload([$key]);

        return self::$loaded[$key];
    }

    /**
     * Everything a client needs to show a file well: its address, its dimensions (so the
     * page doesn't jump when the image arrives) and each rendition's.
     *
     * @return array<string, mixed>|null
     */
    public static function ref(?string $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }
        $upload = self::find($key);
        if ($upload === null) {
            return ['key' => $key, 'url' => self::url($key)];
        }
        $renditions = [];
        foreach ($upload->renditions ?? [] as $name => $rendition) {
            $renditions[$name] = [
                'url' => self::url($rendition['key']),
                'width' => $rendition['width'],
                'height' => $rendition['height'],
                'size' => $rendition['size'],
            ];
        }

        return [
            'key' => $upload->key,
            'url' => self::url($upload->key),
            'name' => $upload->name,
            'mime' => $upload->mime,
            'size' => $upload->size,
            'width' => $upload->width,
            'height' => $upload->height,
            'optimised' => $upload->optimised,
            'renditions' => (object) $renditions,
        ];
    }

    public static function forget(): void
    {
        self::$loaded = [];
    }

    /**
     * A few pictures to seed an image field with, made here so a seeded shop has something
     * to look at without downloading anything. They go through the same optimiser as a
     * real upload, so their renditions exist too.
     *
     * @return list<string> keys
     */
    public static function placeholders(Descriptor $resource, Field $field, int $count = 6): array
    {
        if (! ImageOptimizer::available() || ! function_exists('imagepng')) {
            return [];
        }
        $palette = [[111, 0, 255], [0, 168, 150], [240, 113, 103], [38, 70, 83], [233, 196, 106], [72, 149, 239], [155, 93, 229], [42, 157, 143]];
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            [$r, $g, $b] = $palette[$i % count($palette)];
            $image = imagecreatetruecolor(800, 800);
            // A soft top-to-bottom gradient with a lighter disc: enough to tell cards apart.
            for ($y = 0; $y < 800; $y++) {
                $shade = 1 - $y / 1600;
                imageline($image, 0, $y, 799, $y, imagecolorallocate($image, (int) ($r * $shade), (int) ($g * $shade), (int) ($b * $shade)));
            }
            $light = imagecolorallocate($image, min(255, $r + 90), min(255, $g + 90), min(255, $b + 90));
            imagefilledellipse($image, 400, 400, 360, 360, $light);
            imagefilledellipse($image, 400, 400, 200, 200, imagecolorallocate($image, 255, 255, 255));

            $path = self::scratchFile();
            imagepng($image, $path);
            try {
                $keys[] = self::store($resource, $field, strtolower("{$resource->name}-".($i + 1).'.png'), $path, 'image/png')->key;
            } finally {
                @unlink($path);
            }
        }

        return $keys;
    }

    /**
     * A path for a file that lives for one request. In the app's own storage, not the system's
     * temporary folder: a web server's PHP often can't write there, and tempnam() then picks
     * somewhere else and says so with a notice Laravel treats as an error.
     */
    public static function scratchFile(): string
    {
        $dir = storage_path('framework/cache/nevela');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir.DIRECTORY_SEPARATOR.Str::uuid().'.tmp';
    }

    /**
     * Write a file, and fail loudly if it wasn't written. Laravel's disks answer false by
     * default instead of throwing, and a record must never point at a file that isn't there.
     *
     * @throws RuntimeException
     */
    private static function write(Filesystem $disk, string $key, string $bytes, ?string $visibility = null): void
    {
        $stored = $visibility === null ? $disk->put($key, $bytes) : $disk->put($key, $bytes, $visibility);
        if ($stored === false) {
            throw new RuntimeException("The file could not be written to storage ({$key}). Check the disk in config/nevela.php and that it is writable.");
        }
    }

    private static function remember(Upload $upload): Upload
    {
        return self::$loaded[$upload->key] = $upload;
    }

    /**
     * Decoding a photo needs about five bytes per pixel, which a 12-megapixel phone picture
     * takes past PHP's usual 128 MB. Raised for this request only.
     */
    private static function makeRoom(): void
    {
        $wanted = (string) config('nevela.uploads.memory', '512M');
        $bytes = fn (string $value): int => (int) $value * match (strtoupper(substr(trim($value), -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };
        $current = (string) ini_get('memory_limit');
        if ($current !== '-1' && $bytes($current) < $bytes($wanted)) {
            @ini_set('memory_limit', $wanted);
        }
    }

    /** @throws RuntimeException */
    public static function assertStorable(): void
    {
        if (! array_key_exists(self::disk(), (array) config('filesystems.disks', []))) {
            throw new RuntimeException('The disk "'.self::disk().'" in config/nevela.php (uploads.disk) is not defined in config/filesystems.php.');
        }
    }
}
