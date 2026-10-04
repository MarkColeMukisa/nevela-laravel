<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Naming;

final class MakeResourceCommand extends Command
{
    protected $signature = 'nevela:resource
        {name : PascalCase resource name, e.g. Product}
        {--fields= : "name:string, sku:string!, price:money, status:enum(draft|live), notes:text?"}
        {--icon= : Lucide icon name for the dashboard, e.g. package}
        {--group= : Sidebar group in the dashboard}
        {--force : Overwrite an existing descriptor}
        {--migrate : Run the new migration straight away}
        {--seed= : Fill the new resource with records too (25, or the number you give). Runs the migration first}';

    protected $description = 'Describe a resource once, then generate its Laravel API and Flare dashboard descriptor';

    public function handle(): int
    {
        try {
            $descriptor = Descriptor::fromSpec(
                $this->argument('name'),
                (string) ($this->option('fields') ?? $this->ask('Fields (e.g. name:string, price:money, active:boolean)')),
                $this->option('icon'),
                $this->option('group'),
            );
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $dir = rtrim(config('nevela.descriptors_path'), '/\\');
        $path = $dir.DIRECTORY_SEPARATOR.Naming::kebab($descriptor->name).'.json';
        if (is_file($path) && ! $this->option('force')) {
            $this->components->error("{$descriptor->name} already has a descriptor. Edit it and run: php artisan nevela:generate {$descriptor->name}");

            return self::FAILURE;
        }
        // Checked before anything is written: a relation needs the resource it points at.
        foreach ($descriptor->fields as $field) {
            if ($field->kind === 'belongsTo' && $field->target !== $descriptor->name && ! is_file($dir.DIRECTORY_SEPARATOR.Naming::kebab((string) $field->target).'.json')) {
                $this->components->error("{$field->name} belongs to {$field->target}, which doesn't exist yet. Create it first: php artisan nevela:resource {$field->target} --fields=\"name:string\"");

                return self::FAILURE;
            }
        }
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $descriptor->toJson());
        $this->components->info("Described {$descriptor->name} in ".str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));

        Nevela::forget();
        $status = GenerateCommand::generate($this, [$descriptor], Nevela::all(), false);

        // "--seed" on its own and "--seed=100" both count; without a number it is 25.
        $seed = $this->input->hasParameterOption('--seed') || $this->option('seed') !== null;
        $migrate = $this->option('migrate') || $seed;

        if ($status === self::SUCCESS && $migrate) {
            $status = $this->call('migrate');
        }
        if ($status === self::SUCCESS && $seed) {
            $status = $this->call('nevela:seed', ['name' => $descriptor->name, '--count' => $this->option('seed') ?: 25]);
        }

        $this->newLine();
        $this->line($migrate
            ? '  Next: tighten <fg=cyan>app/Policies/'.$descriptor->name.'Policy.php</>.'
            : '  Next: <fg=cyan>php artisan migrate</>, then tighten <fg=cyan>app/Policies/'.$descriptor->name.'Policy.php</>.');
        $this->line("  API:  GET|POST /{$this->prefix()}{$descriptor->slug} · GET|PUT|PATCH|DELETE /{$this->prefix()}{$descriptor->slug}/{id} · GET /{$this->prefix()}{$descriptor->slug}/_stats");

        return $status;
    }

    private function prefix(): string
    {
        $prefix = trim((string) config('nevela.prefix', 'api'), '/');

        return $prefix === '' ? '' : $prefix.'/';
    }
}
