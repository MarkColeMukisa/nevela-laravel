<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Nevela\Laravel\Generator\GeneratedFile;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Generator\Writer;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Adoption;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Naming;

final class AdoptCommand extends Command
{
    protected $signature = 'nevela:adopt
        {table : The table to make a resource of, e.g. posts}
        {--name= : The resource\'s name, when it isn\'t the table\'s own (posts is Post)}
        {--icon= : Lucide icon name for the dashboard, e.g. newspaper}
        {--group= : Sidebar group in the dashboard}
        {--yes : Write it without asking}
        {--force : Replace the descriptor this table already has}';

    protected $description = 'Make a resource of a table you already have: its descriptor is read from the table, which is left as it is';

    public function handle(): int
    {
        $table = (string) $this->argument('table');
        if (str_starts_with($table, 'nevela_') || $table === 'migrations') {
            $this->components->error("{$table} is a table Nevela or Laravel keeps for itself.");

            return self::FAILURE;
        }

        Nevela::forget();
        try {
            $all = Nevela::all();
            // The descriptor this table has already, if it is being adopted again.
            $others = array_values(array_filter($all, fn (Descriptor $d) => $d->table !== $table));
            $existing = array_values(array_filter($all, fn (Descriptor $d) => $d->table === $table))[0] ?? null;
            if ($existing !== null && ! $this->option('force')) {
                $this->components->error("{$table} is a resource already. Edit its descriptor and run: php artisan nevela:generate. To read it from the table again, add --force.");

                return self::FAILURE;
            }
            $name = $this->option('name');
            // Read again, it stays the resource it is. Under another name the first descriptor
            // would still be there, and the table would be two resources.
            if ($existing !== null && $name !== null && Naming::pascal($name) !== $existing->name) {
                $this->components->error("{$table} is the {$existing->name} resource, and --force reads it again under that name. To call it something else, remove nevela/resources/".Naming::kebab($existing->name).'.json and the code generated for it, then adopt the table again.');

                return self::FAILURE;
            }
            $adoption = Adoption::of($table, $others, $existing?->name ?? $name, $this->option('icon') ?? $existing?->icon, $this->option('group') ?? $existing?->group);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $descriptor = $adoption->descriptor;

        $dir = rtrim(config('nevela.descriptors_path'), '/\\');
        $path = $dir.DIRECTORY_SEPARATOR.Naming::kebab($descriptor->name).'.json';
        foreach ($others as $other) {
            if ($other->name === $descriptor->name) {
                $this->components->error("There is a {$descriptor->name} already, on the {$other->table} table. Give this one another name: --name=…");

                return self::FAILURE;
            }
        }
        if ($taken = $this->ownFiles($descriptor, [...$others, $descriptor])) {
            $this->components->error("The name {$descriptor->name} is taken by code of your own, which Nevela won't write over:");
            $this->components->bulletList($taken);
            $this->line("  Adopt the table under another name (<fg=cyan>--name=…</>), or move those files aside first.");

            return self::FAILURE;
        }

        $this->components->info("{$table} as {$descriptor->name}, with ".($descriptor->idType === 'int' ? 'integer' : 'UUID').' ids');
        foreach ($adoption->fields as $column => $field) {
            $this->components->twoColumnDetail($column, "{$field->name}:".Adoption::spec($field));
        }
        foreach ($adoption->left as $left) {
            $this->components->twoColumnDetail($left['column'], "<fg=gray>left alone: {$left['why']}</>");
        }
        if ($adoption->notes !== []) {
            $this->newLine();
            $this->components->bulletList($adoption->notes);
        }

        if (! $this->option('yes') && ! $this->components->confirm("Make {$descriptor->name} a resource? The table is not changed", true)) {
            $this->components->info('Nothing was written.');

            return self::SUCCESS;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $descriptor->toJson());
        $this->components->info("Described {$descriptor->name} in ".str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));

        Nevela::forget();
        $status = GenerateCommand::generate($this, [$descriptor], Nevela::all(), false);

        $this->newLine();
        $this->line('  Next: <fg=cyan>php artisan migrate</> gives the table a trash (a deleted_at column), then tighten <fg=cyan>app/Policies/'.$descriptor->name.'Policy.php</>.');
        $this->line('  Every column came in as the plainest type that holds it. Where one is an email, a slug or a choice, say so in the descriptor and run <fg=cyan>php artisan nevela:generate</>.');

        return $status;
    }

    /**
     * Files this resource would be written to that are there already and aren't Nevela's:
     * a model of the same name, most often. Their generated block has nowhere to go, and a
     * resource whose model lacks it only half works.
     *
     * @param  list<Descriptor>  $all
     * @return list<string>
     */
    private function ownFiles(Descriptor $descriptor, array $all): array
    {
        $roots = [GeneratedFile::TARGET_API => base_path()];
        if (($web = config('nevela.web_path')) && is_dir($web)) {
            $roots[GeneratedFile::TARGET_WEB] = $web;
        }
        $taken = [];
        foreach ((new ResourceGenerator)->forResource($descriptor, null, $all) as $file) {
            $root = $roots[$file->target] ?? null;
            $absolute = $root === null ? null : rtrim($root, '/\\').DIRECTORY_SEPARATOR.$file->path;
            if ($file->mode === GeneratedFile::MODE_BLOCK && $absolute !== null && is_file($absolute) && Writer::block((string) file_get_contents($absolute)) === null) {
                $taken[] = $file->path;
            }
        }

        return $taken;
    }
}
