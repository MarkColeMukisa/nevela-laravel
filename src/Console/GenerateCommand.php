<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Generator\Writer;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Descriptor;

final class GenerateCommand extends Command
{
    protected $signature = 'nevela:generate
        {name? : Only this resource (e.g. Product). Default: every descriptor}
        {--force : Rewrite generated blocks even where you edited inside them}';

    protected $description = 'Regenerate Laravel code and Flare descriptors from nevela/resources/*.json';

    /**
     * Whether a resource's table needs the migration that gives it a trash.
     *
     * Not when its own migration already makes the column, and not when the column is
     * there without one of ours having been written: then it came from a migration of
     * the app's own, and ours would take that column away again when rolled back.
     *
     * @param  list<string>  $createMigrations  The contents of the table's own migration(s); none for a resource that is new
     * @param  bool  $written  Whether an add_trash_to_… migration exists for the table already
     * @param  bool  $columnExists  Whether the table has deleted_at in the database now
     */
    public static function needsTrashMigration(array $createMigrations, bool $written, bool $columnExists): bool
    {
        if ($createMigrations === []) {
            return false;
        }
        foreach ($createMigrations as $contents) {
            if (preg_match('/softDeletes|deleted_at/', $contents) === 1) {
                return false;
            }
        }

        return $written || ! $columnExists;
    }

    /** Whether the table has the column now. No, where there is no database to ask. */
    private static function hasTrashColumn(string $table): bool
    {
        try {
            return Schema::hasColumn($table, 'deleted_at');
        } catch (\Throwable) {
            return false;
        }
    }

    public function handle(): int
    {
        Nevela::forget();
        try {
            $all = Nevela::all();
            $targets = $this->argument('name') ? [Nevela::resource($this->argument('name'))] : $all;
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        // With no descriptors there is still an (empty) route file and web registry to
        // write, so a new app's dashboard builds before its first resource exists.
        $status = self::generate($this, $targets, $all, (bool) $this->option('force'));
        if ($all === []) {
            $this->components->warn('No descriptors yet. Start with: php artisan nevela:resource Product --fields="name:string, price:money"');
        }

        return $status;
    }

    /**
     * The top of the project, where `php nevela` lives: nevela.root_path if set, otherwise
     * two levels up when the app sits in <project>/apps/<name>. Null for a Laravel app that
     * isn't inside such a project, which gets no launcher.
     */
    public static function projectRoot(): ?string
    {
        $configured = config('nevela.root_path');
        if ($configured === false) {
            return null;
        }
        if (is_string($configured) && $configured !== '') {
            return is_dir($configured) ? rtrim((string) realpath($configured), '/\\') : null;
        }
        $base = base_path();

        return basename(dirname($base)) === 'apps' ? dirname($base, 2) : null;
    }

    /** @param list<Descriptor> $targets @param list<Descriptor> $all */
    public static function generate(Command $command, array $targets, array $all, bool $force): int
    {
        $generator = new ResourceGenerator;
        $byName = [];
        foreach ($all as $descriptor) {
            $byName[$descriptor->name] = $descriptor;
        }

        // A relation is written on both ends, so the resource a new one points at is
        // regenerated with it: Category gains its products when Product arrives.
        $queue = [];
        foreach ($targets as $descriptor) {
            $queue[$descriptor->name] = $descriptor;
            foreach ($descriptor->fields as $field) {
                if ($field->kind !== 'belongsTo') {
                    continue;
                }
                if (! isset($byName[$field->target])) {
                    $command->components->error("{$descriptor->name}.{$field->name} belongs to {$field->target}, which doesn't exist yet. Create it first: php artisan nevela:resource {$field->target} --fields=\"name:string\"");

                    return self::FAILURE;
                }
                $queue[$field->target] ??= $byName[$field->target];
            }
        }

        $files = [];
        foreach ($queue as $descriptor) {
            array_push($files, ...$generator->forResource($descriptor, null, $all));
            // A table made before there was a trash gets a migration that gives it one. A new
            // resource's own migration, written by the line above, already has the column.
            $made = array_map(fn (string $file) => (string) file_get_contents($file), glob(database_path("migrations/*_create_{$descriptor->table}_table.php")) ?: []);
            $written = (glob(database_path("migrations/*_add_trash_to_{$descriptor->table}_table.php")) ?: []) !== [];
            if (self::needsTrashMigration($made, $written, self::hasTrashColumn($descriptor->table))) {
                $files[] = $generator->trashMigrationFile($descriptor);
            }
        }
        $files[] = $generator->routes($all);
        $files[] = $generator->registry($all);
        $files[] = $generator->authConfig((array) config('nevela.auth', []));

        $roots = ['api' => base_path()];
        if (($web = config('nevela.web_path')) && is_dir($web)) {
            $roots['web'] = $web;
        }
        if ($root = self::projectRoot()) {
            $roots['root'] = $root;
            $apiFromRoot = ltrim(str_replace('\\', '/', substr(base_path(), strlen($root))), '/');
            $files[] = $generator->launcher($apiFromRoot);
        }

        $report = (new Writer($roots, $force))->write($files);
        $edited = 0;
        // Shown relative to the Laravel app, like the dashboard's files: ../../nevela
        $rootShown = isset($apiFromRoot) ? rtrim(str_repeat('../', substr_count($apiFromRoot, '/') + 1), '/') : '';
        foreach ($report as $row) {
            $path = $row['path'];
            if (isset($roots['root']) && str_starts_with($path, $roots['root'].DIRECTORY_SEPARATOR) && ! str_starts_with($path, base_path().DIRECTORY_SEPARATOR)) {
                $path = $rootShown.substr($path, strlen($roots['root']));
            }
            $path = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
            $command->components->twoColumnDetail($path, match ($row['status']) {
                'created' => '<fg=green>created</>',
                'updated' => '<fg=blue>updated</>',
                'edited' => '<fg=yellow>skipped — edited inside generated block</>',
                'exists' => '<fg=gray>exists (yours)</>',
                'skipped' => '<fg=gray>skipped (no web app at nevela.web_path yet)</>',
                default => '<fg=gray>unchanged</>',
            });
            $edited += $row['status'] === 'edited' ? 1 : 0;
        }
        if ($edited) {
            $command->components->warn("{$edited} file(s) skipped because you edited their generated block. Move your changes outside it, or rerun with --force.");
        }

        return self::SUCCESS;
    }
}
