<?php

namespace Nevela\Laravel\Support;

use Nevela\Laravel\Generator\GeneratedFile;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Generator\Writer;

/**
 * What taking a resource out of the app means, worked out without changing anything:
 * `nevela resource:remove` shows this, and with --force carries it out.
 *
 * Only what is still exactly as Nevela wrote it is Nevela's to delete. Everything else is
 * the app's: a file changed since, a generated block edited inside, code beside the block.
 * The table and its migrations stay; the data is never touched here.
 */
final class Removal
{
    /** Exactly as Nevela wrote it: deleted. */
    public const DELETE = 'delete';

    /** Nevela's block in a file that is otherwise the app's (an adopted model): the block goes, the rest stays. */
    public const UNBLOCK = 'unblock';

    /** Changed since Nevela wrote it: left for the app to remove, unless asked. */
    public const YOURS = 'yours';

    /** The table's migrations: its history, kept. */
    public const MIGRATION = 'migration';

    /**
     * @param  list<array{path: string, action: string}>  $files  absolute paths
     * @param  list<string>  $blockers  why it can't be removed yet
     * @param  list<string>  $mentions  absolute paths of the app's own files that still name it
     */
    public function __construct(
        public readonly Descriptor $resource,
        public readonly array $files,
        public readonly array $blockers,
        public readonly array $mentions,
    ) {}

    /**
     * @param  list<Descriptor>  $all  every resource in the app, this one included
     * @param  array<string, string>  $roots  target => absolute directory, as the generator writes them
     * @param  string  $descriptorFile  the resource's JSON
     */
    public static function plan(Descriptor $resource, array $all, array $roots, string $descriptorFile, ResourceGenerator $generator): self
    {
        $files = [['path' => $descriptorFile, 'action' => self::DELETE]];
        foreach ($generator->forResource($resource, null, $all) as $file) {
            $root = $roots[$file->target] ?? null;
            // The create-table migration is found by its glob; the others are what this run would write.
            if ($root === null || str_starts_with($file->path, 'database/migrations/')) {
                continue;
            }
            $absolute = rtrim($root, '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file->path);
            if (is_file($absolute)) {
                $files[] = ['path' => $absolute, 'action' => self::judge($file, (string) file_get_contents($absolute), $resource)];
            }
        }
        if (isset($roots[GeneratedFile::TARGET_API])) {
            $migrations = rtrim($roots[GeneratedFile::TARGET_API], '/\\').DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR;
            foreach (["*_create_{$resource->table}_table.php", "*_add_trash_to_{$resource->table}_table.php", "*_add_confirm_to_{$resource->table}_table.php"] as $pattern) {
                foreach (glob($migrations.$pattern) ?: [] as $migration) {
                    $files[] = ['path' => $migration, 'action' => self::MIGRATION];
                }
            }
        }

        // Another resource that belongs to this one would be left pointing at nothing.
        $blockers = [];
        foreach ($all as $other) {
            if ($other->name === $resource->name) {
                continue;
            }
            foreach ($other->fields as $field) {
                if ($field->kind === 'belongsTo' && $field->target === $resource->name) {
                    $blockers[] = "{$other->name}.{$field->name} belongs to {$resource->name}. Take that field out of ".Naming::kebab($other->name).'.json first, or remove '.$other->name.'.';
                }
            }
        }

        $leaving = array_map(fn (array $file) => $file['path'], $files);

        return new self($resource, $files, $blockers, self::mentions($resource, $roots, $leaving));
    }

    /** @return list<string> */
    public function paths(string $action): array
    {
        return array_values(array_map(fn (array $file) => $file['path'], array_filter($this->files, fn (array $file) => $file['action'] === $action)));
    }

    /** What a file of the resource's is now: still Nevela's, partly, or the app's. */
    public static function judge(GeneratedFile $file, string $current, Descriptor $resource): string
    {
        $current = str_replace("\r\n", "\n", $current);
        if ($file->mode === GeneratedFile::MODE_ONCE) {
            if ($current === $file->contents) {
                return self::DELETE;
            }
            // A loading skeleton counts the fields there were when it was written: one made
            // before the resource grew is still Nevela's, with other numbers in it.
            $counts = fn (string $contents) => preg_replace('/\d+/', '#', $contents);

            return str_ends_with($file->path, 'loading.tsx') && $counts($current) === $counts($file->contents) ? self::DELETE : self::YOURS;
        }

        $block = Writer::block($current);
        // No block, or one edited inside since it was written: the app's.
        if ($block === null || $block['hash'] !== Writer::hash($block['body'])) {
            return self::YOURS;
        }
        $template = Writer::stamp($file->contents);
        $theirs = Writer::block($template);
        // Around the block, only code counts: a comment Nevela has worded differently since
        // doesn't make the file the app's.
        $outside = fn (string $contents, array $at) => implode("\n", array_filter(
            array_map('trim', explode("\n", substr($contents, 0, $at['offset']).substr($contents, $at['offset'] + strlen($at['raw'])))),
            fn (string $line) => $line !== '' && ! preg_match('#^(//|/\*|\*|\#)#', $line),
        ));
        if ($theirs !== null && $outside($current, $block) === $outside($template, $theirs)) {
            return self::DELETE;
        }

        // Code of the app's own around the block. In an adopted model that is the model it
        // had before Nevela: only the block goes. Anywhere else, the app has built on it.
        return $resource->adopted && str_starts_with($file->path, 'app/Models/') ? self::UNBLOCK : self::YOURS;
    }

    /** A file with Nevela's block taken out, as it was before Nevela put it there. */
    public static function withoutBlock(string $contents): string
    {
        $block = Writer::block($contents);

        return $block === null ? $contents : substr($contents, 0, $block['offset']).substr($contents, $block['offset'] + strlen($block['raw']));
    }

    /**
     * The app's own files that still name the resource: code that will break when its model,
     * or its dashboard pages, have gone. Only told, never changed.
     *
     * @param  list<string>  $leaving
     * @return list<string>
     */
    private static function mentions(Descriptor $resource, array $roots, array $leaving): array
    {
        $name = preg_quote($resource->name, '/');
        $kebab = preg_quote(Naming::kebab($resource->name), '/');
        $slug = preg_quote($resource->slug, '/');
        $looks = [
            GeneratedFile::TARGET_API => [['app', 'routes', 'database/seeders', 'database/factories', 'tests', 'config'], '/\.php$/', "/App\\\\Models\\\\{$name}\\b|\\b{$name}::|\\bnew {$name}\\b|\\b{$name} \\$|\\b{$name}Policy\\b/"],
            GeneratedFile::TARGET_WEB => [['app', 'components', 'lib'], '/\.(ts|tsx)$/', "/{$kebab}\\.resource\\b|\\/dashboard\\/{$slug}\\b/"],
        ];
        // Regenerated without it straight after: not the app's to fix.
        $regenerated = ['routes/nevela.php', 'routes/nevela-public.php', 'resources/index.ts'];

        $found = [];
        foreach ($looks as $target => [$folders, $extension, $pattern]) {
            $root = $roots[$target] ?? null;
            if ($root === null) {
                continue;
            }
            $root = rtrim($root, '/\\');
            foreach ($folders as $folder) {
                $dir = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $folder);
                if (! is_dir($dir)) {
                    continue;
                }
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($files as $file) {
                    $path = $file->getPathname();
                    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
                    if (! preg_match($extension, $path) || in_array($path, $leaving, true) || in_array($relative, $regenerated, true) || str_contains($relative, 'node_modules/')) {
                        continue;
                    }
                    // What a generated block says is regenerated: another resource's hasMany goes with it.
                    if (preg_match($pattern, self::withoutBlock((string) file_get_contents($path))) === 1) {
                        $found[] = $path;
                    }
                }
            }
        }
        sort($found);

        return $found;
    }
}
