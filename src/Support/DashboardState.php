<?php

namespace Nevela\Laravel\Support;

/**
 * What an app remembers about its dashboard, in apps/web/.nevela.json.
 *
 * - `template`: the version of the dashboard the app is on.
 * - `files`: a fingerprint of every template file at that version. Comparing the app's
 *   files against these is how an update tells "you changed this" from "you didn't",
 *   with nothing to download and nothing to guess.
 * - `dependencies`: the template's package.json dependencies at that version, for the
 *   same question about each dependency.
 * - `history`: what each update did.
 *
 * Commit the file. Backups and incoming files live beside it in .nevela/, which ignores
 * itself.
 */
final class DashboardState
{
    /** Files the installer personalises for every app (its name); never "changed by you". */
    public const PERSONALISED = ['package.json', 'lib/site.ts'];

    /** @param array<string, string>|null $files @param array<string, array<string, string>>|null $dependencies @param list<array<string, mixed>> $history */
    public function __construct(
        public ?string $template = null,
        public ?array $files = null,
        public ?array $dependencies = null,
        public array $history = [],
    ) {}

    public static function path(string $web): string
    {
        return rtrim($web, '/\\').'/.nevela.json';
    }

    public static function read(string $web): self
    {
        $data = json_decode((string) @file_get_contents(self::path($web)), true);
        if (! is_array($data)) {
            return new self;
        }

        return new self(
            isset($data['template']) ? (string) $data['template'] : null,
            isset($data['files']) && is_array($data['files']) ? $data['files'] : null,
            isset($data['dependencies']) && is_array($data['dependencies']) ? $data['dependencies'] : null,
            isset($data['history']) && is_array($data['history']) ? array_values($data['history']) : [],
        );
    }

    public function write(string $web): void
    {
        $files = $this->files ?? [];
        ksort($files);
        file_put_contents(self::path($web), json_encode(array_filter([
            'template' => $this->template,
            'files' => $files ?: null,
            'dependencies' => $this->dependencies,
            'history' => $this->history ?: null,
        ], fn ($value) => $value !== null), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * Record a template as the one this app is now on.
     *
     * @param  array<string, string>  $templateFiles  path => contents
     */
    public function adopt(string $version, array $templateFiles): void
    {
        $this->template = $version;
        $this->files = DashboardUpdate::hashes($templateFiles);
        $package = json_decode($templateFiles['package.json'] ?? '{}', true) ?: [];
        $this->dependencies = array_filter([
            'dependencies' => $package['dependencies'] ?? null,
            'devDependencies' => $package['devDependencies'] ?? null,
        ]);
    }

    /** The old template's package.json, as far as merging needs it, or null when it wasn't recorded. */
    public function packageJson(): ?string
    {
        return $this->dependencies === null ? null : json_encode($this->dependencies);
    }

    /**
     * The app's own fingerprints for a set of paths.
     *
     * @param  iterable<string>  $paths
     * @return array<string, string|null>
     */
    public static function fingerprints(string $web, iterable $paths): array
    {
        $web = rtrim($web, '/\\');
        $yours = [];
        foreach ($paths as $path) {
            $yours[$path] = is_file("{$web}/{$path}") ? DashboardUpdate::hash((string) file_get_contents("{$web}/{$path}")) : null;
        }

        return $yours;
    }

    /**
     * The dashboard files this app has made its own, leaving out the two the installer
     * personalises for everyone.
     *
     * @return array{changed: list<string>, deleted: list<string>}|null null when no fingerprints were recorded
     */
    public function yourChanges(string $web): ?array
    {
        if ($this->files === null) {
            return null;
        }
        $base = array_diff_key($this->files, array_flip(self::PERSONALISED));

        return DashboardUpdate::changes($base, self::fingerprints($web, array_keys($base)));
    }
}
