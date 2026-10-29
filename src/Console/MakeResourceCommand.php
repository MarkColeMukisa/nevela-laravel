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
        {--id=uuid : What a record\'s id is: uuid, or int for the numbered ids of $table->id()}
        {--items= : Lines edited inside its form and saved with it, as a resource of their own: "OrderItem: product:belongsTo(Product), quantity:int, price:money"}
        {--unique=* : Fields whose values go together once only: --unique=student,course is one enrolment for each student and course}
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
                (string) $this->option('id'),
            );
            $unique = array_map(fn ($set) => Descriptor::uniqueSet($descriptor->fields, (string) $set), (array) $this->option('unique'));
            if ($unique !== []) {
                $descriptor = Descriptor::fromArray(['unique' => $unique] + $descriptor->toArray());
            }
            $lines = $this->option('items') === null ? null : self::lines($descriptor, (string) $this->option('items'));
            if ($lines !== null) {
                $descriptor = Descriptor::fromArray(['items' => $lines->name] + $descriptor->toArray());
            }
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $dir = rtrim(config('nevela.descriptors_path'), '/\\');
        $described = $lines === null ? [$descriptor] : [$descriptor, $lines];
        foreach ($described as $one) {
            if (is_file($dir.DIRECTORY_SEPARATOR.Naming::kebab($one->name).'.json') && ! $this->option('force')) {
                $this->components->error("{$one->name} already has a descriptor. Edit it and run: php artisan nevela:generate {$one->name}");

                return self::FAILURE;
            }
            // Checked before anything is written: a relation needs the resource it points at.
            foreach ($one->fields as $field) {
                if ($field->kind === 'belongsTo' && $field->target !== $one->name && $field->target !== $descriptor->name && ! is_file($dir.DIRECTORY_SEPARATOR.Naming::kebab((string) $field->target).'.json')) {
                    $this->components->error("{$field->name} belongs to {$field->target}, which doesn't exist yet. Create it first: php artisan nevela:resource {$field->target} --fields=\"name:string\"");

                    return self::FAILURE;
                }
            }
        }
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        foreach ($described as $one) {
            $path = $dir.DIRECTORY_SEPARATOR.Naming::kebab($one->name).'.json';
            file_put_contents($path, $one->toJson());
            $this->components->info("Described {$one->name} in ".str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
        }

        Nevela::forget();
        $status = GenerateCommand::generate($this, $described, Nevela::all(), false);

        // "--seed" on its own and "--seed=100" both count; without a number it is 25.
        $seed = $this->input->hasParameterOption('--seed') || $this->option('seed') !== null;
        $migrate = $this->option('migrate') || $seed;

        if ($status === self::SUCCESS && $migrate) {
            $status = $this->call('migrate');
        }
        if ($status === self::SUCCESS && $seed) {
            $count = (int) ($this->option('seed') ?: 25);
            $status = $this->call('nevela:seed', ['name' => $descriptor->name, '--count' => $count]);
            // Three lines each, on average: some have more, some none.
            if ($status === self::SUCCESS && $lines !== null) {
                $status = $this->call('nevela:seed', ['name' => $lines->name, '--count' => $count * 3]);
            }
        }

        $this->newLine();
        $this->line($migrate
            ? '  Next: tighten <fg=cyan>app/Policies/'.$descriptor->name.'Policy.php</>.'
            : '  Next: <fg=cyan>php artisan migrate</>, then tighten <fg=cyan>app/Policies/'.$descriptor->name.'Policy.php</>.');
        $this->line("  API:  GET|POST /{$this->prefix()}{$descriptor->slug} · GET|PUT|PATCH|DELETE /{$this->prefix()}{$descriptor->slug}/{id} · GET /{$this->prefix()}{$descriptor->slug}/_stats");

        return $status;
    }

    /**
     * The lines `--items` describes: "OrderItem: product:belongsTo(Product), quantity:int".
     * The name comes first, then their fields. Grit's "InvoiceItem:description:string,qty:int"
     * reads the same. They are given the field that points at what they are lines of,
     * unless one of theirs already does.
     */
    public static function lines(Descriptor $of, string $spec): Descriptor
    {
        [$name, $fields] = array_map('trim', explode(':', $spec, 2) + [1 => '']);
        if ($name === '' || $fields === '') {
            throw new InvalidArgumentException('--items is the lines\' name and then their fields: --items="'.$of->name.'Item: product:belongsTo(Product), quantity:int, price:money".');
        }
        $lines = Descriptor::fromSpec($name, $fields);
        foreach ($lines->fields as $field) {
            if ($field->kind === 'belongsTo' && $field->target === $of->name) {
                return $lines;
            }
        }

        return Descriptor::fromSpec($name, $fields.', '.Naming::camel($of->name).':belongsTo('.$of->name.')');
    }

    private function prefix(): string
    {
        $prefix = trim((string) config('nevela.prefix', 'api'), '/');

        return $prefix === '' ? '' : $prefix.'/';
    }
}
