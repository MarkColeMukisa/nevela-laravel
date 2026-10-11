<?php

namespace Nevela\Laravel\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A descriptor for a table that is already there, read from the table itself.
 *
 * `php artisan nevela:adopt posts` is for an app that had its tables before it had Nevela.
 * Nothing about the table is changed by reading it, and what can't be said in a descriptor
 * is left out of it and listed: a column Nevela doesn't know about stays in the table,
 * untouched by the API, for the app's own code to use.
 *
 * Types are taken as they are and no tighter. An `email` column is adopted as a string,
 * not as an email, because every value in it was valid when it was saved and a stricter
 * rule would refuse to save an old record again. Tightening is for whoever knows the data.
 */
final class Adoption
{
    private const INTEGERS = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'int2', 'int4', 'int8', 'serial', 'bigserial', 'year'];

    private const DECIMALS = ['decimal', 'numeric', 'float', 'double', 'real', 'float4', 'float8', 'double precision', 'money'];

    private const STRINGS = ['varchar', 'char', 'bpchar', 'nvarchar', 'nchar', 'character varying', 'character', 'string'];

    private const DATETIMES = ['datetime', 'timestamp', 'timestamptz', 'datetime2', 'datetimeoffset', 'smalldatetime'];

    /** Columns every resource has already, by another name. */
    private const OWN = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * @param  array<string, Field>  $fields  The fields, keyed by the column each was read from
     * @param  list<array{column: string, why: string}>  $left  Columns that are not in the descriptor
     * @param  list<string>  $notes  What whoever adopts the table should know
     */
    private function __construct(
        public readonly Descriptor $descriptor,
        public readonly array $fields,
        public readonly array $left,
        public readonly array $notes,
    ) {}

    /**
     * Read a table from the database.
     *
     * @param  list<Descriptor>  $resources  The resources the app has: a foreign key to one of their tables is a relation
     *
     * @throws InvalidArgumentException when the table can't be a resource, with the reason
     */
    public static function of(string $table, array $resources = [], ?string $name = null, ?string $icon = null, ?string $group = null): self
    {
        if (! Schema::hasTable($table)) {
            throw new InvalidArgumentException("There is no table called {$table}.");
        }

        $columns = Schema::getColumns($table);

        return self::describe($table, $columns, Schema::getIndexes($table), Schema::getForeignKeys($table), $resources, $name, $icon, $group, self::longest($table, $columns));
    }

    /**
     * The longest value in each string column that has no length of its own.
     *
     * PostgreSQL's `varchar` can be declared without one, and SQLite keeps none at all,
     * so nothing about such a column says how long its values may be. The values do.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, int> by column
     */
    private static function longest(string $table, array $columns): array
    {
        $unbounded = [];
        foreach ($columns as $column) {
            if (in_array(strtolower((string) ($column['type_name'] ?? '')), self::STRINGS, true) && ! str_contains((string) ($column['type'] ?? ''), '(')) {
                $unbounded[] = (string) $column['name'];
            }
        }
        if ($unbounded === []) {
            return [];
        }
        try {
            $grammar = DB::connection()->getQueryGrammar();
            $row = (array) DB::table($table)->selectRaw(implode(', ', array_map(fn (string $column) => 'max(length('.$grammar->wrap($column).')) as '.$grammar->wrap($column), $unbounded)))->first();
        } catch (\Throwable) {
            return []; // a database that measures some other way: the usual length, as for any string
        }

        return array_map(fn ($length) => (int) $length, $row);
    }

    /**
     * The same, from what Laravel's schema builder says about a table: getColumns(),
     * getIndexes() and getForeignKeys().
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $indexes
     * @param  list<array<string, mixed>>  $foreignKeys
     * @param  list<Descriptor>  $resources
     * @param  array<string, int>  $longest  The longest value in a string column that declares no length, by column
     *
     * @throws InvalidArgumentException
     */
    public static function describe(string $table, array $columns, array $indexes, array $foreignKeys, array $resources = [], ?string $name = null, ?string $icon = null, ?string $group = null, array $longest = []): self
    {
        $name = Naming::pascal($name ?? Str::singular($table));
        $byName = [];
        foreach ($columns as $column) {
            $byName[(string) $column['name']] = $column;
        }

        $idType = self::idType($table, $byName, $indexes);
        $missing = array_values(array_diff(['created_at', 'updated_at'], array_keys($byName)));
        if ($missing !== []) {
            throw new InvalidArgumentException("{$table} has no ".implode(' or ', $missing).'. Nevela sorts and counts records by when they were made and changed: add the columns with a migration ($table->timestamps()), then adopt it.');
        }

        $unique = [];
        foreach ($indexes as $index) {
            if (($index['unique'] ?? false) && ! ($index['primary'] ?? false) && count($index['columns'] ?? []) === 1) {
                $unique[(string) $index['columns'][0]] = true;
            }
        }
        // column => the table it points at, for a foreign key on that one column to an id.
        $parents = [];
        foreach ($foreignKeys as $key) {
            if (count($key['columns'] ?? []) === 1 && ($key['foreign_columns'] ?? []) === ['id']) {
                $parents[(string) $key['columns'][0]] = (string) $key['foreign_table'];
            }
        }
        $tables = [$table => $name];
        foreach ($resources as $resource) {
            $tables[$resource->table] ??= $resource->name;
        }

        $fields = [];
        $left = [];
        $notes = [];
        foreach ($byName as $column => $info) {
            if (in_array($column, self::OWN, true)) {
                continue;
            }
            $required = ! ($info['nullable'] ?? false);
            $leave = function (string $why) use (&$left, &$notes, $column, $info, $required) {
                $left[] = ['column' => $column, 'why' => $why];
                if ($required && ($info['default'] ?? null) === null && ! ($info['auto_increment'] ?? false) && ($info['generation'] ?? null) === null) {
                    $notes[] = "{$column} has to have a value and has no default, and it isn't in the descriptor, so creating a record from the dashboard will fail. Give the column a default, or make it nullable.";
                }
            };

            $field = Naming::camel($column);
            if (! preg_match('/^[a-z][A-Za-z0-9]*$/', $field) || Naming::snake($field) !== $column || in_array($field, Field::RESERVED, true)) {
                $leave("its name can't be written as a field");

                continue;
            }
            if (($info['generation'] ?? null) !== null) {
                $leave('the database works it out');

                continue;
            }
            if (preg_match('/(^|_)(password|secret|token)$/', $column)) {
                $leave('it looks like a secret');

                continue;
            }

            if (isset($parents[$column])) {
                $target = $tables[$parents[$column]] ?? null;
                if ($target !== null && str_ends_with($column, '_id')) {
                    $fields[$column] = new Field($field, 'belongsTo', required: $required, unique: isset($unique[$column]), target: $target);

                    continue;
                }
                $notes[] = $target === null
                    ? "{$column} points at {$parents[$column]}, which isn't a resource, so it is adopted as a plain value. Adopt {$parents[$column]} first and it becomes a relation."
                    : "{$column} points at {$parents[$column]}, but a relation's column ends in _id, so it is adopted as a plain value.";
            }

            $kind = self::kind($info);
            if ($kind === null) {
                $leave(strtolower((string) ($info['type_name'] ?? 'this type')).' has no field kind');

                continue;
            }
            // No length of its own, and more in it already than the usual one allows.
            if ($kind['kind'] === 'string' && ! isset($kind['maxLength']) && ($longest[$column] ?? 0) > 255) {
                $kind['maxLength'] = max(65_535, $longest[$column]);
                $notes[] = "{$column} declares no length and holds values longer than 255 characters, so it is adopted with room for ".number_format($kind['maxLength']).'.';
            }
            $default = isset($unique[$column]) ? null : self::default($kind, $info['default'] ?? null);
            try {
                $fields[$column] = new Field($field, $kind['kind'], required: $required, unique: isset($unique[$column]), options: $kind['options'] ?? [], maxLength: $kind['maxLength'] ?? null, default: $default);
            } catch (InvalidArgumentException $e) {
                $leave($e->getMessage());
            }
        }
        if ($fields === []) {
            throw new InvalidArgumentException("{$table} has no column that can be a field.");
        }
        // The app's own soft deletes become Nevela's trash, which doesn't keep things for ever.
        if (isset($byName['deleted_at'])) {
            $notes[] = "{$table} has a deleted_at column already, so the rows it marks as deleted are in Nevela's trash from the start, and the trash removes for good what has been in it longer than trash.days (30 unless you changed it). If those rows are to be kept, set trash.days to null in config/nevela.php before anyone opens the Trash page.";
        }

        $keyed = [];
        foreach ($fields as $field) {
            $keyed[$field->name] = $field;
        }
        $base = Descriptor::make($name, $keyed);

        return new self(
            new Descriptor($name, $keyed, $table, $base->slug, $base->label, $base->pluralLabel, $icon, $group, null, $idType, true),
            $fields,
            $left,
            $notes,
        );
    }

    /**
     * What the table's ids are. A resource has one id column, called id, holding integers or UUIDs.
     *
     * @param  array<string, array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $indexes
     */
    private static function idType(string $table, array $columns, array $indexes): string
    {
        $primary = null;
        foreach ($indexes as $index) {
            if ($index['primary'] ?? false) {
                $primary = array_values($index['columns'] ?? []);
            }
        }
        $id = $columns['id'] ?? null;
        if ($id === null || ($primary !== null && $primary !== ['id']) || ($primary === null && ! ($id['auto_increment'] ?? false))) {
            throw new InvalidArgumentException("{$table} can't be a resource: a resource's primary key is one column called id.");
        }
        $type = strtolower((string) ($id['type_name'] ?? ''));
        $full = strtolower((string) ($id['type'] ?? $type));

        return match (true) {
            in_array($type, self::INTEGERS, true) => 'int',
            in_array($type, ['uuid', 'uniqueidentifier'], true), in_array($type, self::STRINGS, true) && str_contains($full, '(36)') => 'uuid',
            default => throw new InvalidArgumentException("{$table} can't be a resource: its ids are neither integers nor UUIDs."),
        };
    }

    /**
     * The field kind a column is, or null when there isn't one for it.
     *
     * @param  array<string, mixed>  $column
     * @return array{kind: string, maxLength?: int, options?: list<string>}|null
     */
    private static function kind(array $column): ?array
    {
        $type = strtolower((string) ($column['type_name'] ?? ''));
        $full = strtolower((string) ($column['type'] ?? $type));
        $length = preg_match('/\((\d+)\)/', $full, $m) ? (int) $m[1] : null;

        return match (true) {
            in_array($type, ['bool', 'boolean', 'bit'], true), $type === 'tinyint' && $length === 1 => ['kind' => 'boolean'],
            in_array($type, self::INTEGERS, true) => ['kind' => 'int'],
            in_array($type, self::DECIMALS, true) => ['kind' => 'float'],
            $type === 'date' => ['kind' => 'date'],
            in_array($type, self::DATETIMES, true) => ['kind' => 'datetime'],
            // nvarchar(max) is SQL Server's long text.
            in_array($type, self::STRINGS, true) && str_contains($full, '(max)') => ['kind' => 'text'],
            // Its own length where it has one that isn't the usual: a value that fit the column has to fit the rule.
            in_array($type, self::STRINGS, true) => ['kind' => 'string'] + ($length !== null && $length !== 255 ? ['maxLength' => $length] : []),
            in_array($type, ['uuid', 'uniqueidentifier'], true) => ['kind' => 'string', 'maxLength' => 36],
            $type === 'tinytext' => ['kind' => 'string'],
            in_array($type, ['text', 'ntext'], true) => ['kind' => 'text'],
            $type === 'mediumtext' => ['kind' => 'text', 'maxLength' => 16_777_215],
            $type === 'longtext' => ['kind' => 'text', 'maxLength' => 4_294_967_295],
            $type === 'enum' => self::choices((string) ($column['type'] ?? '')),
            default => null,
        };
    }

    /**
     * A column's default, as the field's: 'pending', 0, false. Each database writes it its
     * own way ('pending'::character varying), and one that is worked out when a row is
     * made (CURRENT_TIMESTAMP, nextval(…)) is the column's business and is left there.
     *
     * @param  array{kind: string, options?: list<string>}  $kind
     */
    private static function default(array $kind, mixed $written): string|int|float|bool|null
    {
        if (! is_scalar($written) || (string) $written === '') {
            return null;
        }
        $text = trim((string) $written);
        // PostgreSQL adds what type it is; SQL Server wraps it in brackets.
        $text = (string) preg_replace('/::[a-z_ ]+(\([0-9, ]*\))?(\[\])?$/i', '', $text);
        while (str_starts_with($text, '(') && str_ends_with($text, ')')) {
            $text = substr($text, 1, -1);
        }
        $quoted = strlen($text) >= 2 && $text[0] === "'" && str_ends_with($text, "'");
        $value = $quoted ? str_replace("''", "'", substr($text, 1, -1)) : $text;
        if (! $quoted && (strcasecmp($value, 'null') === 0 || preg_match('/[()]|^current_/i', $value))) {
            return null;
        }

        return match ($kind['kind']) {
            'string', 'text' => $value === '' ? null : $value,
            'enum' => in_array($value, $kind['options'] ?? [], true) ? $value : null,
            'int' => filter_var($value, FILTER_VALIDATE_INT) === false ? null : (int) $value,
            'float' => is_numeric($value) ? (float) $value : null,
            'boolean' => match (strtolower($value)) {
                '1', 'true', 't' => true,
                '0', 'false', 'f' => false,
                default => null,
            },
            default => null,
        };
    }

    /**
     * A MySQL enum column: its values, where they are ones an enum field can have.
     *
     * @return array{kind: string, options?: list<string>}
     */
    private static function choices(string $type): array
    {
        preg_match_all("/'((?:[^']|'')*)'/", $type, $m);
        $options = $m[1];
        foreach ($options as $option) {
            if (! preg_match('/^[A-Za-z0-9_-]+$/', $option)) {
                return ['kind' => 'string'];
            }
        }

        return $options === [] ? ['kind' => 'string'] : ['kind' => 'enum', 'options' => $options];
    }

    /** One field as it is written after --fields, e.g. "excerpt:string(500)?". */
    public static function spec(Field $field): string
    {
        $type = match (true) {
            $field->kind === 'belongsTo' => "belongsTo({$field->target})",
            $field->kind === 'enum' => 'enum('.implode('|', $field->options).')',
            $field->maxLength !== null => "{$field->kind}({$field->maxLength})",
            default => $field->kind,
        };
        $default = $field->default === null ? '' : '='.(is_bool($field->default) ? ($field->default ? 'true' : 'false') : $field->default);

        return $type.($field->required ? '' : '?').($field->unique ? '!' : '').$default;
    }
}
