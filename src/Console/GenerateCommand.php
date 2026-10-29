<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Generator\Writer;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Naming;

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
     * @param  bool  $adopted  Whether the table was there before Nevela: then there may be no migration of that name to read
     */
    public static function needsTrashMigration(array $createMigrations, bool $written, bool $columnExists, bool $adopted = false): bool
    {
        if ($createMigrations === [] && ! $adopted) {
            return false;
        }
        foreach ($createMigrations as $contents) {
            if (preg_match('/softDeletes|deleted_at/', $contents) === 1) {
                return false;
            }
        }

        return $written || ! $columnExists;
    }

    /**
     * Whether fields made unique together need the migration that adds their index: when
     * the table was made without it, or was there before Nevela. A new resource's own
     * migration has it.
     *
     * @param  list<string>  $createMigrations  The contents of the table's own migration(s); none for a resource that is new
     */
    public static function needsUniqueMigration(array $createMigrations, string $index, bool $adopted = false): bool
    {
        if ($createMigrations === []) {
            return $adopted;
        }

        return ! str_contains(implode("\n", $createMigrations), $index);
    }

    /**
     * Whether a resource whose form proves an address needs the migration that adds the
     * column for it: when its table was made without one. A new resource's own migration
     * has it.
     *
     * @param  list<string>  $createMigrations  The contents of the table's own migration(s); none for a resource that is new
     */
    public static function needsConfirmMigration(array $createMigrations, bool $adopted = false): bool
    {
        if ($createMigrations === []) {
            return $adopted;
        }
        foreach ($createMigrations as $contents) {
            if (str_contains($contents, 'confirmed_at')) {
                return false;
            }
        }

        return true;
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
        try {
            // A money field that names no currency is shown in the app's.
            $generator = new ResourceGenerator(config('nevela.currency') ?: null);
        } catch (InvalidArgumentException $e) {
            $command->components->error($e->getMessage());

            return self::FAILURE;
        }
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
            // price copied from product.price: the product has a price, of the same kind.
            foreach ($descriptor->fields as $field) {
                if ($field->from === null || ! str_contains($field->from, '.')) {
                    continue;
                }
                [$relation, $source] = explode('.', $field->from);
                $target = $byName[$descriptor->fields[$relation]->target];
                $from = $target->fields[$source] ?? null;
                if ($from === null || $from->kind !== $field->kind) {
                    $command->components->error("{$descriptor->name}.{$field->name} is copied from {$target->name}.{$source}, ".($from === null ? "which {$target->name} doesn't have." : "which is a {$from->kind} and not a {$field->kind}."));

                    return self::FAILURE;
                }
            }
        }
        // Lines are a resource that belongs to this one, and they have no lines of their own.
        foreach ($queue as $descriptor) {
            if ($descriptor->items === null) {
                continue;
            }
            $lines = $generator->lineItems($descriptor, $all);
            if ($lines === null) {
                $field = Naming::camel($descriptor->name);
                $command->components->error("{$descriptor->name}'s items are {$descriptor->items}, which ".(isset($byName[$descriptor->items]) ? "doesn't belong to {$descriptor->name}. Give it a field {$field}:belongsTo({$descriptor->name})." : "doesn't exist yet. Create it: php artisan nevela:resource {$descriptor->items} --fields=\"{$field}:belongsTo({$descriptor->name}), …\""));

                return self::FAILURE;
            }
            if ($lines['resource']->items !== null) {
                $command->components->error("{$descriptor->items} is {$descriptor->name}'s items, so it can't have items of its own.");

                return self::FAILURE;
            }
            // Saving an order's lines changes several at once, and two swapping their products
            // would clash halfway through: not yet.
            if ($lines['resource']->unique !== []) {
                $command->components->error("{$descriptor->items} is {$descriptor->name}'s items, and lines can't have fields unique together yet. Take \"unique\" out of its descriptor.");

                return self::FAILURE;
            }
        }

        $files = [];
        // A second apart, in the order given: made together, an order's table is created
        // before the table of its lines, which points at it.
        $now = time();
        foreach (array_values($queue) as $index => $descriptor) {
            array_push($files, ...$generator->forResource($descriptor, date('Y_m_d_His', $now + $index), $all));
            // A table made before there was a trash gets a migration that gives it one. A new
            // resource's own migration, written by the line above, already has the column.
            $made = array_map(fn (string $file) => (string) file_get_contents($file), glob(database_path("migrations/*_create_{$descriptor->table}_table.php")) ?: []);
            $written = (glob(database_path("migrations/*_add_trash_to_{$descriptor->table}_table.php")) ?: []) !== [];
            if (self::needsTrashMigration($made, $written, self::hasTrashColumn($descriptor->table), $descriptor->adopted)) {
                $files[] = $generator->trashMigrationFile($descriptor);
            }
            // The same for "confirm" added to a resource whose table is already made.
            if ($descriptor->public?->confirm !== null && self::needsConfirmMigration($made, $descriptor->adopted)) {
                $files[] = $generator->confirmMigrationFile($descriptor);
            }
            // And for fields made unique together once the table was there.
            foreach ($descriptor->unique as $set) {
                if (self::needsUniqueMigration($made, $generator->uniqueIndex($descriptor, $set), $descriptor->adopted)) {
                    $files[] = $generator->uniqueMigrationFile($descriptor, $set);
                }
            }
        }
        $files[] = $generator->routes($all);
        $files[] = $generator->publicRoutes($all);
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
        // A model written before its resource was made public has no scope saying which
        // records are, and without one none are. Said, so an empty public list isn't a puzzle.
        foreach ($queue as $descriptor) {
            $model = base_path("app/Models/{$descriptor->name}.php");
            if ($descriptor->public?->read !== null && is_file($model) && ! str_contains((string) file_get_contents($model), 'scopePublicQuery')) {
                $command->components->warn("{$descriptor->name} can be read publicly, but its model doesn't say which records may be, so none are. Add to app/Models/{$descriptor->name}.php: public function scopePublicQuery(\\Illuminate\\Database\\Eloquent\\Builder \$query): void { … }");
            }
        }

        return self::SUCCESS;
    }
}
