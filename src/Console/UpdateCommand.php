<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\DashboardState;
use Nevela\Laravel\Support\DashboardUpdate;
use Nevela\Laravel\Support\Releases;
use Nevela\Laravel\Support\TemplateSource;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class UpdateCommand extends Command
{
    protected $signature = 'nevela:update
        {--check : Show what would change, and change nothing}
        {--skip-package : Leave the nevela/laravel package as it is; update only the dashboard}
        {--undo : Put the dashboard back as it was before the last update}';

    protected $description = 'Update Nevela: the package, the generated code and the dashboard';

    /** Apps created before the dashboard recorded its version all came from this template. */
    private const FIRST_TRACKED = '0.1.1';

    public function handle(): int
    {
        if ($this->option('undo')) {
            return $this->undo();
        }
        $check = (bool) $this->option('check');

        if (! $this->option('skip-package')) {
            $status = $this->updatePackage($check);
            if ($status !== null) {
                return $status;
            }
        }

        if (! $check) {
            $this->components->task('Regenerating from the descriptors', fn () => $this->callSilently('nevela:generate') === self::SUCCESS);
            $this->modernizeDevScript();
        }

        $status = $this->updateDashboard($check);

        // A new version may bring a table of its own (uploads did). Say so, don't run it:
        // changing a database is the developer's call.
        if (! $check && ($pending = $this->pendingMigrations()) > 0) {
            $this->newLine();
            $this->components->warn("{$pending} migration(s) have not been run. Run them before using the app: php nevela migrate");
        }

        return $status;
    }

    private function pendingMigrations(): int
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return 0;
            }
            $files = $migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]);

            return count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Apps created before 0.1.3 run both servers with scripts/dev.mjs, which always uses
     * port 8000 and so can end up talking to another program that already has it. Point
     * their `dev` script at `php nevela dev`, which picks a free port, unless they changed it.
     */
    private function modernizeDevScript(): void
    {
        $root = GenerateCommand::projectRoot();
        $file = $root ? $root.DIRECTORY_SEPARATOR.'package.json' : null;
        if (! $file || ! is_file($file)) {
            return;
        }
        $source = (string) file_get_contents($file);
        $updated = preg_replace('/("dev"\s*:\s*)"node scripts\/dev\.mjs"/', '$1"php nevela dev"', $source, 1, $count);
        if ($count === 1) {
            file_put_contents($file, $updated);
            $this->components->twoColumnDetail('package.json: dev', '<fg=blue>node scripts/dev.mjs → php nevela dev</>');
        }
    }

    /**
     * Update the Composer package. Returns an exit code when the rest of the work was
     * handed to a fresh process, or null to carry on in this one.
     */
    private function updatePackage(bool $check): ?int
    {
        $installed = Nevela::VERSION;
        $latest = Releases::latest();

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
        $web = $this->webPath();
        if ($web === null) {
            $this->components->info('No dashboard at nevela.web_path, so there is nothing more to update.');

            return self::SUCCESS;
        }
        $state = DashboardState::read($web);
        $from = $state->template ?? self::FIRST_TRACKED;
        // In --check the package hasn't been updated, so look at the newest dashboard there is.
        $to = $check ? (Releases::latest() ?? Nevela::VERSION) : Nevela::VERSION;

        if (version_compare($from, $to, '>=')) {
            $this->components->info("The dashboard is on the latest template ({$from}).");
            // An app from before fingerprints were kept: record them now, so the next update
            // knows what was changed without having to work it out from a download.
            if (! $check && $state->files === null) {
                try {
                    $state->adopt($from, TemplateSource::fetch($from)['files']);
                    $state->write($web);
                } catch (Throwable) {
                    // Not worth failing an up-to-date app over; the next update can still download it.
                }
            }

            return self::SUCCESS;
        }

        try {
            $next = TemplateSource::fetch($to)['files'];
            // What the app is on: its recorded fingerprints, or for an older app the old template itself.
            if ($state->files === null) {
                $state->adopt($from, TemplateSource::fetch($from)['files']);
            }
        } catch (Throwable $e) {
            $this->components->error("Couldn't get the dashboard template: {$e->getMessage()}. The dashboard was left as it is; run this again later.");

            return self::FAILURE;
        }

        $base = $state->files;
        $nextHashes = DashboardUpdate::hashes($next);
        $yours = DashboardState::fingerprints($web, array_unique([...array_keys($base), ...array_keys($next)]));

        // package.json is merged, not replaced: it has your app's name and your own dependencies.
        $packages = null;
        if (isset($next['package.json']) && is_file("{$web}/package.json")) {
            $packages = DashboardUpdate::mergePackageJson((string) file_get_contents("{$web}/package.json"), $state->packageJson(), $next['package.json']);
        }
        unset($base['package.json'], $nextHashes['package.json'], $yours['package.json']);

        $plan = DashboardUpdate::plan($base, $nextHashes, $yours);
        $this->components->info(($check ? 'Dashboard: what would change, ' : 'Dashboard: ')."template {$from} → {$to}");

        $done = [DashboardUpdate::UPDATE => [], DashboardUpdate::ADD => [], DashboardUpdate::CONFLICT => [], DashboardUpdate::REMOVED => []];
        $backup = '.nevela/backups/'.gmdate('Ymd-His')."-{$from}-to-{$to}";
        $incoming = ".nevela/incoming/{$to}";

        foreach ($plan as $path => $action) {
            $done[$action][] = $path;
            if (! $check) {
                if ($action === DashboardUpdate::UPDATE) {
                    // Untouched by you, so identical to the old template. Kept anyway: a copy costs nothing.
                    $this->put("{$web}/{$backup}/{$path}", (string) file_get_contents("{$web}/{$path}"));
                    $this->put("{$web}/{$path}", $next[$path]);
                } elseif ($action === DashboardUpdate::ADD) {
                    $this->put("{$web}/{$path}", $next[$path]);
                } elseif ($action === DashboardUpdate::CONFLICT) {
                    // Yours stays. The new version is put where you can compare the two.
                    $this->put("{$web}/{$incoming}/{$path}", $next[$path]);
                }
            }
            $this->components->twoColumnDetail($path, match ($action) {
                DashboardUpdate::UPDATE => $check ? '<fg=blue>would update</>' : '<fg=blue>updated</>',
                DashboardUpdate::ADD => $check ? '<fg=green>would add</>' : '<fg=green>added</>',
                DashboardUpdate::CONFLICT => '<fg=yellow>kept yours — changed in the template too</>',
                DashboardUpdate::REMOVED => '<fg=gray>no longer in the template</>',
            });
        }

        foreach ($packages['changed'] ?? [] as $name => $change) {
            $this->components->twoColumnDetail("package.json: {$name}", '<fg=blue>'.$change.'</>');
        }
        foreach ($packages['kept'] ?? [] as $name => $versions) {
            $this->components->twoColumnDetail("package.json: {$name}", "<fg=yellow>kept yours, {$versions}</>");
        }
        if ($plan === [] && ! ($packages['changed'] ?? [])) {
            $this->line('  Nothing in the dashboard needed changing.');
        }
        $this->newLine();

        if (! $check) {
            $touched = $done[DashboardUpdate::UPDATE] !== [] || $done[DashboardUpdate::ADD] !== [] || ($packages['changed'] ?? []) !== [];
            if ($packages && $packages['changed']) {
                $this->put("{$web}/{$backup}/package.json", (string) file_get_contents("{$web}/package.json"));
                file_put_contents("{$web}/package.json", $packages['json']);
            }
            if ($touched) {
                // The record as it was, so `--undo` can put that back too.
                $this->put("{$web}/{$backup}/.nevela.json", (string) @file_get_contents(DashboardState::path($web)) ?: '{}');
            }
            // .nevela/ holds copies, not source: it keeps itself out of git.
            if (is_dir("{$web}/.nevela") && ! is_file("{$web}/.nevela/.gitignore")) {
                file_put_contents("{$web}/.nevela/.gitignore", "*\n");
            }

            $state->adopt($to, $next);
            $state->history[] = array_filter([
                'at' => gmdate('c'),
                'from' => $from,
                'to' => $to,
                'updated' => $done[DashboardUpdate::UPDATE],
                'added' => $done[DashboardUpdate::ADD],
                'kept' => $done[DashboardUpdate::CONFLICT],
                'removed' => $done[DashboardUpdate::REMOVED],
                'dependencies' => $packages['changed'] ?? [],
                'backup' => $touched ? $backup : null,
            ]);
            $state->write($web);

            if ($touched) {
                $this->line("  Every file this replaced was copied to <fg=cyan>{$this->relative($web)}/{$backup}</> first. To put it all back: <fg=cyan>php nevela update --undo</>");
            }
        }
        if ($done[DashboardUpdate::CONFLICT] !== []) {
            $count = count($done[DashboardUpdate::CONFLICT]);
            $this->components->warn("{$count} file(s) changed in the template and in your app. Yours were kept.".($check ? '' : " The template's new versions are in {$this->relative($web)}/{$incoming} for you to compare."));
        }
        if ($done[DashboardUpdate::REMOVED] !== []) {
            $this->line('  '.count($done[DashboardUpdate::REMOVED])." file(s) are no longer part of the template. They were left in place; delete them if you don't use them.");
        }
        if (! $check && ($packages['changed'] ?? [])) {
            $this->line('  The dashboard\'s dependencies changed. Install them: <fg=cyan>cd '.$this->relative($web).' && '.DevCommand::packageManager($web).' install</>');
        }

        return self::SUCCESS;
    }

    /** Put the dashboard back as it was before the last update that changed it. */
    private function undo(): int
    {
        $web = $this->webPath();
        if ($web === null) {
            $this->components->error('No dashboard at nevela.web_path.');

            return self::FAILURE;
        }
        $state = DashboardState::read($web);
        $last = null;
        foreach (array_reverse($state->history) as $entry) {
            if (isset($entry['backup']) && is_dir("{$web}/{$entry['backup']}")) {
                $last = $entry;
                break;
            }
        }
        if ($last === null) {
            $this->components->info('There is no update to undo: no backup was found.');

            return self::SUCCESS;
        }

        $dir = "{$web}/{$last['backup']}";
        $restored = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            if ($path === '.nevela.json') {
                continue;
            }
            $this->put("{$web}/{$path}", (string) file_get_contents($file->getPathname()));
            $this->components->twoColumnDetail($path, '<fg=blue>restored</>');
            $restored++;
        }
        // Files the update added are taken out again, unless you have changed them since.
        foreach ($last['added'] ?? [] as $path) {
            $recorded = $state->files[$path] ?? null;
            if (is_file("{$web}/{$path}") && $recorded !== null && DashboardUpdate::hash((string) file_get_contents("{$web}/{$path}")) === $recorded) {
                unlink("{$web}/{$path}");
                $this->components->twoColumnDetail($path, '<fg=gray>removed (the update had added it)</>');
            } elseif (is_file("{$web}/{$path}")) {
                $this->components->twoColumnDetail($path, '<fg=yellow>kept: you changed it after the update</>');
            }
        }
        // The record goes back too, so the same update can be run again later.
        if (is_file("{$dir}/.nevela.json")) {
            copy("{$dir}/.nevela.json", DashboardState::path($web));
        }

        $this->newLine();
        $this->components->info("Put back {$restored} file(s). The dashboard is as it was before the update from {$last['from']} to {$last['to']}.");
        $this->line('  The nevela/laravel package was not changed by this. To go back a version there too: <fg=cyan>composer require nevela/laravel:'.$last['from'].'</>');

        return self::SUCCESS;
    }

    /** The dashboard's folder, with forward slashes, or null when there isn't one. */
    private function webPath(): ?string
    {
        $web = config('nevela.web_path');
        if (! $web || ! is_dir($web)) {
            return null;
        }

        return rtrim(str_replace('\\', '/', realpath($web) ?: $web), '/');
    }

    private function put(string $file, string $contents): void
    {
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, $contents);
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

    /** A path as someone at the top of the project would type it: apps/web/… */
    private function relative(string $path): string
    {
        $root = GenerateCommand::projectRoot();
        $root = $root === null ? null : rtrim(str_replace('\\', '/', $root), '/');
        if ($root !== null && str_starts_with($path, $root.'/')) {
            return substr($path, strlen($root) + 1);
        }
        $parent = dirname(rtrim(str_replace('\\', '/', base_path()), '/'));

        return str_starts_with($path, $parent.'/') ? '../'.substr($path, strlen($parent) + 1) : $path;
    }
}
