<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Generator\Writer;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use Nevela\Laravel\Support\Naming;

/**
 * Adds fields to a resource that already has a table: to its descriptor, to everything
 * generated from that, and to the table, with a migration of its own.
 */
final class FieldCommand extends Command
{
    protected $signature = 'nevela:field
        {name : The resource, e.g. Invoice}
        {fields?* : The new fields, written as in --fields of nevela:resource: dueDate:date}
        {--fields= : The same, several at once: "dueDate:date, terms:enum(net15|net30)?"}
        {--migrate : Run the new migration straight away}';

    protected $description = 'Add fields to a resource: its descriptor, its generated code and its table';

    public function handle(): int
    {
        $spec = implode(', ', [...(array) $this->argument('fields'), ...array_filter([$this->option('fields')])]);
        try {
            $resource = Nevela::resource(Naming::pascal($this->argument('name')));
            $fields = Descriptor::fieldsOf($spec !== '' ? $spec : (string) $this->ask('Fields (e.g. dueDate:date, terms:enum(net15|net30)?)'));
            if ($fields === []) {
                throw new InvalidArgumentException('Say which fields to add: nevela field '.$resource->name.' dueDate:date');
            }
            $this->refuseWhatIsThere($resource, $fields);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $path = rtrim(config('nevela.descriptors_path'), '/\\').DIRECTORY_SEPARATOR.Naming::kebab($resource->name).'.json';
        $before = (string) file_get_contents($path);
        // Added to the file as it is, so what else is in it stays as it was written.
        $data = json_decode($before, true);
        foreach ($fields as $name => $field) {
            $data['fields'][$name] = $field->toArray();
        }
        $after = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        // Whether it has a table to add to: one still to be made is made with these fields.
        $madeAlready = $resource->adopted || (glob(database_path("migrations/*_create_{$resource->table}_table.php")) ?: []) !== [];

        try {
            file_put_contents($path, $after);
            Nevela::forget();
            $all = Nevela::all();
            $status = GenerateCommand::generate($this, [Nevela::resource($resource->name)], $all, false);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());
            $status = self::FAILURE;
        }
        // Whatever is wrong with them, the resource is left as it was.
        if ($status !== self::SUCCESS) {
            file_put_contents($path, $before);
            Nevela::forget();

            return $status;
        }

        $this->components->info('Added '.implode(', ', array_keys($fields))." to {$resource->name} in ".str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));

        $described = Nevela::resource($resource->name);
        $columns = array_values(array_filter($fields, fn (Field $field) => ! self::hasColumn($resource->table, $field->column())));
        if ($madeAlready && $columns !== []) {
            $file = (new ResourceGenerator(config('nevela.currency') ?: null, config('nevela.phone_country') ?: null))->addFieldsMigrationFile($described, $columns, self::nextTimestamp(), $all);
            (new Writer(['api' => base_path()]))->write([$file]);
            $this->components->twoColumnDetail($file->path, '<fg=green>created</>');
        }

        $migrate = (bool) $this->option('migrate');
        if ($migrate && ($status = $this->call('migrate')) !== self::SUCCESS) {
            return $status;
        }

        $this->newLine();
        if (! $migrate) {
            $this->line('  Next: <fg=cyan>nevela migrate</>.');
        }
        $this->mentionEarlierRecords($resource, $fields);

        return self::SUCCESS;
    }

    /** @param array<string, Field> $fields */
    private function refuseWhatIsThere(Descriptor $resource, array $fields): void
    {
        $columns = array_map(fn (Field $field) => $field->column(), $resource->fields);
        foreach ($fields as $name => $field) {
            if (isset($resource->fields[$name]) || in_array($field->column(), $columns, true)) {
                throw new InvalidArgumentException("{$resource->name} already has a field \"{$name}\". To change it, edit nevela/resources/".Naming::kebab($resource->name).'.json and run: nevela generate '.$resource->name);
            }
        }
    }

    /** Said only where it is so: a required field, and records from before it. */
    private function mentionEarlierRecords(Descriptor $resource, array $fields): void
    {
        $asked = array_filter($fields, fn (Field $field) => $field->alwaysRequired() && $field->default === null && $field->kind !== 'boolean');
        if ($asked === [] || ! self::hasRecords($resource->table)) {
            return;
        }
        $this->line('  '.$resource->pluralLabel.' already there have no '.implode(' or ', array_map(fn (string $name) => strtolower(Naming::humanize($name)), array_keys($asked))).' yet. The form asks for it the next time each is edited.');
    }

    /**
     * After every migration there is, or the column would be added before its table is made:
     * migrations run in the order of their names, and a new resource's can be dated a moment ahead.
     */
    private static function nextTimestamp(): string
    {
        $now = time();
        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            $date = \DateTimeImmutable::createFromFormat('Y_m_d_His', substr(basename($file), 0, 17));
            if ($date !== false && $date->getTimestamp() >= $now) {
                $now = $date->getTimestamp() + 1;
            }
        }

        return date('Y_m_d_His', $now);
    }

    /** No, where there is no database to ask. */
    private static function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function hasRecords(string $table): bool
    {
        try {
            return DB::table($table)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
