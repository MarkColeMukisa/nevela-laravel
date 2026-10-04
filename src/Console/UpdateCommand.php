<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\DashboardUpdate;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class UpdateCommand extends Command
{
    protected $signature = 'nevela:update
        {--check : Show what would change, and change nothing}
        {--skip-package : Leave the nevela/laravel package as it is; update only the dashboard}';

    protected $description = 'Update Nevela: the package, the generated code and the dashboard';

    /** Apps created before the dashboard recorded its version all came from this template. */
    private const FIRST_TRACKED = '0.1.1';

    public function handle(): int
    {
        $check = (bool) $this->option('check');

        if (! $this->option('skip-package')) {
            $status = $this->updatePackage($check);
            if ($status !== null) {
                return $status;
            }
        }

        if (! $check) {
            $this->components->task('Regenerating from the descriptors', fn () => $this->callSilently('nevela:generate') === self::SUCCESS);
        }

        return $this->updateDashboard($check);
    }

    /**
     * Update the Composer package. Returns an exit code when the rest of the work was
     * handed to a fresh process, or null to carry on in this one.
     */
    private function updatePackage(bool $check): ?int
    {
        $installed = Nevela::VERSION;
        $latest = $this->latestOnPackagist();

        if ($folder = $this->installedByPath()) {
            $this->components->warn("nevela/laravel is installed from a folder ({$folder}), so Composer can't update it. The dashboard is checked against the version you have, {$installed}.");
            $this->line('  To move to Packagist: remove the "nevela" entry under "repositories" in composer.json, then run <fg=cyan>composer require nevela/laravel</>.');
            $this->newLine();

            return null;
        }

        if ($latest === null) {
            $this->components->warn("Couldn't reach Packagist to look for a newer version. Carrying on with {$installed}.");

            return null;
        }
        if (version_compare($latest, $installed, '<=')) {
            $this->components->info("nevela/laravel {$installed} is the latest.");

            return null;
        }
        if ($check) {
            $this->components->info("nevela/laravel {$installed} → {$latest} is available.");

            return null;
        }

        $this->components->info("Updating nevela/laravel {$installed} → {$latest}");
        $composer = new Process(['composer', 'update', 'nevela/laravel', '--no-interaction', '--no-progress'], base_path(), null, null, null);
        $composer->run(fn ($type, $buffer) => $this->output->write($buffer));
        if (! $composer->isSuccessful()) {
            $this->components->error('Composer could not update nevela/laravel. Nothing else was changed.');

            return self::FAILURE;
        }

        // Composer succeeds even when composer.json doesn't allow the new version. Read what
        // is actually on disk now, not what this process loaded before the update.
        $source = (string) @file_get_contents(base_path('vendor/nevela/laravel/src/Nevela.php'));
        $now = preg_match("/const VERSION = '([^']+)'/", $source, $m) ? $m[1] : $installed;
        if ($now === $installed) {
            $this->components->warn("Composer kept {$installed}: the constraint in composer.json doesn't allow {$latest}. Set \"nevela/laravel\" to \"^".implode('.', array_slice(explode('.', $latest), 0, 2)).'" there and run this again.');

            return null;
        }

        // The package's own files have just been replaced. Finish in a new process, so the
        // rest runs on the new code instead of a mix of old and new.
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $rest = new Process([$php, 'artisan', 'nevela:update', '--skip-package'], base_path(), null, null, null);
        $rest->run(fn ($type, $buffer) => $this->output->write($buffer));

        return $rest->getExitCode() ?? self::FAILURE;
    }

    private function updateDashboard(bool $check): int
    {
        $web = config('nevela.web_path');
        if (! $web || ! is_dir($web)) {
            $this->components->info('No dashboard at nevela.web_path, so there is nothing more to update.');

            return self::SUCCESS;
        }
        $web = rtrim(str_replace('\\', '/', realpath($web) ?: $web), '/');
        $marker = "{$web}/.nevela.json";
        $from = is_file($marker) ? (json_decode((string) file_get_contents($marker), true)['template'] ?? self::FIRST_TRACKED) : self::FIRST_TRACKED;
        // In --check the package hasn't been updated, so look at the newest dashboard there is.
        $to = $check ? ($this->latestOnPackagist() ?? Nevela::VERSION) : Nevela::VERSION;

        if (version_compare($from, $to, '>=')) {
            $this->components->info("The dashboard is on the latest template ({$from}).");

            return self::SUCCESS;
        }

        try {
            $base = $this->template($from);
            $next = $this->template($to);
        } catch (Throwable $e) {
            $this->components->error("Couldn't download the dashboard template ({$e->getMessage()}). The dashboard was left as it is; run this again later.");

            return self::FAILURE;
        }

        $paths = array_unique([...array_keys($base), ...array_keys($next)]);
        $yours = [];
        foreach ($paths as $path) {
            $yours[$path] = is_file("{$web}/{$path}") ? (string) file_get_contents("{$web}/{$path}") : null;
        }

        // package.json is merged, not replaced: it has your app's name and your own dependencies.
        $packages = null;
        if (isset($base['package.json'], $next['package.json'], $yours['package.json'])) {
            $packages = DashboardUpdate::mergePackageJson($yours['package.json'], $base['package.json'], $next['package.json']);
        }
        unset($base['package.json'], $next['package.json']);

        $plan = DashboardUpdate::plan($base, $next, $yours);
        $this->components->info(($check ? 'Dashboard: what would change, ' : 'Dashboard: ')."template {$from} → {$to}");

        $counts = [DashboardUpdate::UPDATE => 0, DashboardUpdate::ADD => 0, DashboardUpdate::CONFLICT => 0, DashboardUpdate::REMOVED => 0];
        foreach ($plan as $path => $action) {
            $counts[$action]++;
            if (! $check && ($action === DashboardUpdate::UPDATE || $action === DashboardUpdate::ADD)) {
                $target = "{$web}/{$path}";
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0775, true);
                }
                file_put_contents($target, $next[$path]);
            }
            $this->components->twoColumnDetail($path, match ($action) {
                DashboardUpdate::UPDATE => $check ? '<fg=blue>would update</>' : '<fg=blue>updated</>',
                DashboardUpdate::ADD => $check ? '<fg=green>would add</>' : '<fg=green>added</>',
                DashboardUpdate::CONFLICT => '<fg=yellow>kept yours — changed in the template too</>',
                DashboardUpdate::REMOVED => '<fg=gray>no longer in the template</>',
            });
        }

        if ($packages && $packages['changed']) {
            foreach ($packages['changed'] as $name => $change) {
                $this->components->twoColumnDetail("package.json: {$name}", '<fg=blue>'.$change.'</>');
            }
            if (! $check) {
                file_put_contents("{$web}/package.json", $packages['json']);
            }
        }
        foreach ($packages['kept'] ?? [] as $name => $versions) {
            $this->components->twoColumnDetail("package.json: {$name}", "<fg=yellow>kept yours, {$versions}</>");
        }

        if ($plan === [] && ! ($packages['changed'] ?? [])) {
            $this->line('  Nothing in the dashboard needed changing.');
        }
        $this->newLine();

        if (! $check) {
            file_put_contents($marker, json_encode(['template' => $to], JSON_PRETTY_PRINT)."\n");
        }
        if ($counts[DashboardUpdate::CONFLICT] > 0) {
            $this->components->warn("{$counts[DashboardUpdate::CONFLICT]} file(s) changed in the template and in your app. Yours were kept. To see the template's version of one: https://github.com/MarkColeMukisa/nevela/tree/v{$to}/apps/web");
        }
        if ($counts[DashboardUpdate::REMOVED] > 0) {
            $this->line("  {$counts[DashboardUpdate::REMOVED]} file(s) are no longer part of the template. They were left in place; delete them if you don't use them.");
        }
        if (! $check && ($packages['changed'] ?? [])) {
            $this->line('  The dashboard\'s dependencies changed. Install them: <fg=cyan>cd '.$this->relative($web).' && pnpm install</> (or npm install).');
        }

        return self::SUCCESS;
    }

    /**
     * The dashboard as it shipped in a version: path => contents.
     *
     * It comes from the create-nevela package on npm, which carries the dashboard and is
     * released with the same version number as this package.
     *
     * @return array<string, string>
     */
    private function template(string $version): array
    {
        $dir = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'nevela-template-'.$version.'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);
        $archive = $dir.DIRECTORY_SEPARATOR.'template.tgz';

        try {
            // NEVELA_TEMPLATE_DIR: a folder of create-nevela-<version>.tgz files to use instead
            // of npm. For working on Nevela itself, and for updating without a connection.
            $local = rtrim((string) env('NEVELA_TEMPLATE_DIR', ''), '/\\');
            if ($local !== '' && is_file("{$local}/create-nevela-{$version}.tgz")) {
                copy("{$local}/create-nevela-{$version}.tgz", $archive);
            } else {
                $response = Http::timeout(60)->get("https://registry.npmjs.org/create-nevela/-/create-nevela-{$version}.tgz");
                if (! $response->successful()) {
                    throw new \RuntimeException("create-nevela {$version} is not on npm (HTTP {$response->status()})");
                }
                file_put_contents($archive, $response->body());
            }
            (new PharData($archive))->extractTo($dir, null, true);

            $root = $dir.DIRECTORY_SEPARATOR.'package'.DIRECTORY_SEPARATOR.'template'.DIRECTORY_SEPARATOR.'web';
            if (! is_dir($root)) {
                throw new \RuntimeException("create-nevela {$version} has no dashboard template in it");
            }
            $files = [];
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                // npm can't carry .gitignore files, so the template holds them as _gitignore.
                $path = preg_replace('#(^|/)_gitignore$#', '$1.gitignore', $path);
                $files[$path] = (string) file_get_contents($file->getPathname());
            }

            return $files;
        } finally {
            $this->removeDirectory($dir);
        }
    }

    /** The newest stable release on Packagist, or null when it can't be reached. */
    private function latestOnPackagist(): ?string
    {
        static $latest = false;
        if ($latest !== false) {
            return $latest;
        }
        try {
            $releases = Http::timeout(10)->get('https://repo.packagist.org/p2/nevela/laravel.json')->json('packages.nevela/laravel') ?? [];
        } catch (Throwable) {
            return $latest = null;
        }
        $versions = [];
        foreach ($releases as $release) {
            if (preg_match('/^v?(\d+\.\d+\.\d+)$/', (string) ($release['version'] ?? ''), $m)) {
                $versions[] = $m[1];
            }
        }
        usort($versions, 'version_compare');

        return $latest = ($versions === [] ? null : end($versions));
    }

    /** The folder Composer installs the package from, when it isn't coming from Packagist. */
    private function installedByPath(): ?string
    {
        $composer = json_decode((string) @file_get_contents(base_path('composer.json')), true) ?: [];
        foreach ($composer['repositories'] ?? [] as $repository) {
            if (($repository['type'] ?? null) === 'path' && str_contains((string) ($repository['url'] ?? ''), 'laravel')) {
                return (string) $repository['url'];
            }
        }

        return null;
    }

    private function relative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/');
        $parent = dirname($base);

        return str_starts_with($path, $parent.'/') ? '../'.substr($path, strlen($parent) + 1) : $path;
    }

    private function removeDirectory(string $dir): void
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
