<?php

namespace Nevela\Laravel\Support;

use Illuminate\Support\Facades\Http;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * The dashboard as it shipped in a given version of Nevela.
 *
 * Tried in order, so an update never depends on one place being up to date:
 *
 * 1. NEVELA_TEMPLATE_DIR, a folder of create-nevela-<version>.tgz files. For working on
 *    Nevela itself, and for updating without a connection.
 * 2. The create-nevela package on npm, which carries the dashboard.
 * 3. The repository's own tag on GitHub. Tags are pushed before anything is published,
 *    so this works in the gap between a release and its npm package.
 */
final class TemplateSource
{
    private const NPM = 'https://registry.npmjs.org/create-nevela/-/create-nevela-%s.tgz';

    private const GITHUB = 'https://codeload.github.com/MarkColeMukisa/nevela/tar.gz/refs/tags/v%s';

    /** Never part of an app's dashboard: build output, installs and local settings. */
    private const NEVER = ['node_modules', '.next', '.env.local', 'tsconfig.tsbuildinfo', 'next-env.d.ts', 'AGENTS.md', 'CLAUDE.md'];

    /**
     * @return array{files: array<string, string>, from: string} path => contents, and where it came from
     */
    public static function fetch(string $version): array
    {
        $dir = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'nevela-template-'.$version.'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $archive = $dir.DIRECTORY_SEPARATOR.'template.tgz';
        $tried = [];

        try {
            $local = rtrim((string) env('NEVELA_TEMPLATE_DIR', ''), '/\\');
            if ($local !== '' && is_file("{$local}/create-nevela-{$version}.tgz")) {
                copy("{$local}/create-nevela-{$version}.tgz", $archive);

                return ['files' => self::read($archive, $dir, 'package/template/web', false), 'from' => 'NEVELA_TEMPLATE_DIR'];
            }

            foreach (['npm' => [self::NPM, 'package/template/web', false], 'GitHub' => [self::GITHUB, "nevela-{$version}/apps/web", true]] as $name => [$url, $root, $filter]) {
                try {
                    $response = Http::timeout(60)->get(sprintf($url, $version));
                    if (! $response->successful()) {
                        $tried[] = "{$name}: HTTP {$response->status()}";

                        continue;
                    }
                    file_put_contents($archive, $response->body());

                    return ['files' => self::read($archive, $dir.DIRECTORY_SEPARATOR.strtolower($name), $root, $filter), 'from' => $name];
                } catch (Throwable $e) {
                    $tried[] = "{$name}: ".$e->getMessage();
                }
            }

            throw new RuntimeException("version {$version} was not found (".implode('; ', $tried).')');
        } finally {
            self::remove($dir);
        }
    }

    /**
     * Unpack an archive and read the dashboard out of it.
     *
     * @param  bool  $filter  Whether this is the repository's own apps/web, whose example resources aren't part of the template
     * @return array<string, string>
     */
    private static function read(string $archive, string $into, string $root, bool $filter): array
    {
        if (! is_dir($into)) {
            mkdir($into, 0775, true);
        }
        (new PharData($archive))->extractTo($into, null, true);
        $base = $into.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $root);
        if (! is_dir($base)) {
            throw new RuntimeException('the archive has no dashboard in it');
        }

        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            if (array_intersect(explode('/', $path), self::NEVER) !== []) {
                continue;
            }
            // npm can't carry .gitignore files, so its template holds them as _gitignore.
            $path = preg_replace('#(^|/)_gitignore$#', '$1.gitignore', $path);
            $files[$path] = (string) file_get_contents($file->getPathname());
        }

        return $filter ? self::withoutExamples($files) : $files;
    }

    /**
     * The repository's dashboard has example resources in it (a Product, say). They are
     * generated from Laravel, not part of what a new app is given.
     *
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    public static function withoutExamples(array $files): array
    {
        $slugs = [];
        foreach ($files as $path => $contents) {
            if (preg_match('#^resources/[^/]+\.resource\.ts$#', $path) && preg_match('/slug:\s*"([^"]+)"/', $contents, $m)) {
                $slugs[] = $m[1];
            }
        }

        return array_filter($files, function (string $path) use ($slugs) {
            if (preg_match('#^resources/[^/]+\.resource\.ts$#', $path) || $path === 'resources/index.ts') {
                return false;
            }
            foreach ($slugs as $slug) {
                if (str_starts_with($path, "app/dashboard/{$slug}/")) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_KEY);
    }

    private static function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
