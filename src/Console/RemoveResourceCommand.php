<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Models\Role;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Naming;
use Nevela\Laravel\Support\Removal;
use Throwable;

/**
 * Take a resource out of both apps: the opposite of `nevela resource`.
 *
 * Without --force it only says what it would do. What is still exactly as Nevela wrote it
 * is deleted; what you have changed is listed and kept, unless --with-yours. The table and
 * its rows stay: --drop-table writes a migration that drops it, for you to read and run.
 */
final class RemoveResourceCommand extends Command
{
    protected $signature = 'nevela:resource:remove
        {name : The resource, as it is named: Product}
        {--force : Do it. Without this, what would happen is shown and nothing changes}
        {--with-yours : Delete the files you have changed as well}
        {--drop-table : Also write a migration that drops the table, for php nevela migrate to run}';

    protected $description = 'Take a resource out of the Laravel app and the dashboard: its code, pages, routes and permissions';

    public function handle(): int
    {
        Nevela::forget();
        try {
            $resource = Nevela::resource((string) $this->argument('name'));
            $all = Nevela::all();
        } catch (InvalidArgumentException) {
            $names = array_map(fn ($d) => $d->name, Nevela::all());
            $this->components->error("There is no resource called {$this->argument('name')}.".($names === [] ? '' : ' The resources are: '.implode(', ', $names).'.'));

            return self::FAILURE;
        }

        $roots = ['api' => base_path()];
        if (($web = config('nevela.web_path')) && is_dir($web)) {
            $roots['web'] = rtrim((string) realpath($web), '/\\');
        }
        $descriptorFile = rtrim((string) config('nevela.descriptors_path'), '/\\').DIRECTORY_SEPARATOR.Naming::kebab($resource->name).'.json';
        $plan = Removal::plan($resource, $all, $roots, $descriptorFile, new ResourceGenerator(config('nevela.currency') ?: null));
        $force = (bool) $this->option('force');
        $withYours = (bool) $this->option('with-yours');

        $this->show($plan, $withYours);
        if ($plan->blockers !== []) {
            $this->newLine();
            foreach ($plan->blockers as $blocker) {
                $this->components->error($blocker);
            }

            return self::FAILURE;
        }
        if (! $force) {
            $this->newLine();
            $this->components->info('Nothing was changed. To do it: nevela resource:remove '.$resource->name.' --force'.($withYours ? ' --with-yours' : '').($this->option('drop-table') ? ' --drop-table' : ''));

            return self::SUCCESS;
        }

        // --- done ---
        $gone = [...$plan->paths(Removal::DELETE), ...($withYours ? $plan->paths(Removal::YOURS) : [])];
        foreach ($gone as $path) {
            @unlink($path);
        }
        foreach ($plan->paths(Removal::UNBLOCK) as $path) {
            file_put_contents($path, Removal::withoutBlock((string) file_get_contents($path)));
        }
        // The folders its pages and actions were in, once there is nothing left in them.
        if (isset($roots['web'])) {
            $this->prune($roots['web'].DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'dashboard'.DIRECTORY_SEPARATOR.$resource->slug);
        }
        $this->prune(base_path('app'.DIRECTORY_SEPARATOR.'Nevela'.DIRECTORY_SEPARATOR.'Actions'.DIRECTORY_SEPARATOR.$resource->name));

        if ($this->option('drop-table')) {
            $file = database_path('migrations'.DIRECTORY_SEPARATOR.date('Y_m_d_His')."_drop_{$resource->table}_table.php");
            file_put_contents($file, $this->dropMigration($resource->name, $resource->table));
            $this->components->warn('Wrote '.$this->relative($file).". It drops {$resource->table} and every row in it when it runs: read it, then php nevela migrate.");
        }

        $roles = $this->forgetPermissions($resource->table);

        Nevela::forget();
        $rest = Nevela::all();
        $this->newLine();
        $this->components->info("Removed {$resource->name}.".($roles > 0 ? " Its permissions came out of {$roles} ".($roles === 1 ? 'role' : 'roles').'.' : '').' Regenerating the rest:');
        $status = GenerateCommand::generate($this, $rest, $rest, false);

        if ($plan->mentions !== []) {
            $this->newLine();
            $this->components->warn("Your own code still mentions {$resource->name}. Change it, or the app won't build:");
            foreach ($plan->mentions as $path) {
                $this->line('  '.$this->relative($path));
            }
        }

        return $status;
    }

    private function show(Removal $plan, bool $withYours): void
    {
        $d = $plan->resource;
        $list = function (string $heading, array $paths, string $note = '') {
            if ($paths === []) {
                return;
            }
            $this->newLine();
            $this->line("  <options=bold>{$heading}</>".($note === '' ? '' : " <fg=gray>{$note}</>"));
            foreach ($paths as $path) {
                $this->line('    '.$this->relative($path));
            }
        };

        $this->newLine();
        $this->line("  Removing <fg=cyan>{$d->name}</> ({$d->slug} in the dashboard, the {$d->table} table):");
        $list('Delete', $plan->paths(Removal::DELETE), 'as Nevela wrote them');
        $list('Take Nevela\'s block out of', $plan->paths(Removal::UNBLOCK), 'the rest of the file is yours, and stays');
        $list($withYours ? 'Delete as well' : 'Leave for you', $plan->paths(Removal::YOURS), $withYours ? 'changed since Nevela wrote them' : 'changed since Nevela wrote them: delete them yourself, or add --with-yours');

        $rows = null;
        try {
            $rows = DB::table($d->table)->count();
        } catch (Throwable) {
        }
        $kept = $rows === null ? "the {$d->table} table isn't there" : "the {$d->table} table stays, with its ".number_format($rows).' '.($rows === 1 ? 'row' : 'rows');
        $kept .= $this->option('drop-table') ? ', until the migration that drops it runs' : '. --drop-table writes a migration that drops it';
        $list('Keep', $plan->paths(Removal::MIGRATION), $kept);
        if ($plan->paths(Removal::MIGRATION) === []) {
            $this->newLine();
            $this->line("  <options=bold>Keep</> <fg=gray>{$kept}</>");
        }

        $this->newLine();
        $this->line('  <options=bold>Then</>');
        $this->line('    regenerate the routes, the dashboard\'s menu and the relations of the resources it points at');
        $this->line("    take {$d->table}.* out of every role");
        if ($plan->mentions !== []) {
            $list('Still mentioned in', $plan->mentions, 'your own code: change it, or the app won\'t build');
        }
    }

    /** Take the resource's permissions out of every role. The built-in users and roles are Nevela's own, and stay. */
    private function forgetPermissions(string $table): int
    {
        if (! Access::ready() || in_array($table, ['users', 'roles'], true)) {
            return 0;
        }
        $changed = 0;
        foreach (Role::query()->get() as $role) {
            $kept = array_values(array_filter($role->grants, fn (string $grant) => ! str_starts_with($grant, "{$table}.")));
            if ($kept !== $role->grants) {
                $role->grants = $kept;
                $role->save();
                $changed++;
            }
        }
        Access::forget();

        return $changed;
    }

    /** Remove a folder and those under it once nothing is left in them. */
    private function prune(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($dir.DIRECTORY_SEPARATOR.$entry)) {
                $this->prune($dir.DIRECTORY_SEPARATOR.$entry);
            }
        }
        if (count(scandir($dir) ?: []) === 2) {
            @rmdir($dir);
        }
    }

    private function dropMigration(string $name, string $table): string
    {
        return <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\Schema;

        // Written by `nevela resource:remove {$name} --drop-table`. When it runs, the {$table}
        // table goes, and every row in it. Read it before you migrate; take a backup first.
        return new class extends Migration
        {
            public function up(): void
            {
                Schema::dropIfExists('{$table}');
            }

            public function down(): void
            {
                // The rows are gone. To have the table back, restore it from a backup.
            }
        };

        PHP;
    }

    /** A path as it reads from the top of the project. */
    private function relative(string $path): string
    {
        $top = GenerateCommand::projectRoot() ?? base_path();

        return str_replace('\\', '/', str_starts_with($path, $top) ? ltrim(substr($path, strlen($top)), '/\\') : $path);
    }
}
