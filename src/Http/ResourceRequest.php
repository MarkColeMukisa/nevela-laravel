<?php

namespace Nevela\Laravel\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator as Validation;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use Nevela\Laravel\Support\Html;
use Nevela\Laravel\Support\Slug;

/**
 * Base for generated resource requests.
 *
 * Input uses the descriptor's camelCase field names (what Flare's client sends); `values()`
 * maps the validated input onto snake_case columns for Eloquent.
 *
 * - POST: rules as written.
 * - PUT (replace): rules as written, and optional fields left out are cleared to null.
 * - PATCH: every rule becomes "sometimes", so only the fields sent are validated/changed.
 * - Keys that aren't fields fail validation instead of being silently dropped.
 */
abstract class ResourceRequest extends FormRequest
{
    /** Keys Flare may echo back that are never writable. */
    protected const READ_ONLY = ['id', 'createdAt', 'updatedAt'];

    /** @return array<string, mixed> */
    abstract public function rules(): array;

    /** @return array<string, string> field => column */
    abstract protected function columns(): array;

    /**
     * Fields that hold rich text. A generated request with any says which.
     *
     * @return list<string>
     */
    protected function rich(): array
    {
        return [];
    }

    /**
     * What a field is when a create leaves it out. A generated request with any says which.
     *
     * @return array<string, mixed> field => value
     */
    protected function defaults(): array
    {
        return [];
    }

    /**
     * Fields made from another when a create leaves them empty: a slug from a title. A
     * generated request with any says which, and where to look for the ones taken.
     *
     * @return array<string, array{from: string, table: string, column: string}>
     */
    protected function made(): array
    {
        return [];
    }

    /**
     * Fill what a create left empty and can be made, and keep a later save from emptying
     * it: once made, a slug is an address, and an address doesn't move.
     *
     * @param  array<string, mixed>  $values  by column
     * @param  array<string, true>  $taken  Values made for earlier rows of the same batch
     * @return array<string, mixed>
     */
    private function make(array $values, bool $creating, array &$taken = []): array
    {
        $columns = $this->columns();
        foreach ($this->made() as $definition) {
            $column = $definition['column'];
            $empty = ! isset($values[$column]) || $values[$column] === '';
            if (! $empty) {
                $taken[(string) $values[$column]] = true;
            } elseif ($creating) {
                $values[$column] = Slug::free($definition['table'], $column, (string) ($values[$columns[$definition['from']] ?? ''] ?? ''), $taken);
            } else {
                unset($values[$column]);
            }
        }

        return $values;
    }

    /**
     * A create from some of the fields only: a public form's, where nobody is asked who
     * they are and so only what the descriptor lists is taken.
     *
     * The rules are this request's own. A key that isn't listed is refused the way an
     * unknown one is, and a field left out takes its default, or is left to the column.
     *
     * @param  list<string>  $fields  The fields that may be sent
     * @param  array<string, mixed>  $input
     * @return array{values: array<string, mixed>, issues: list<array{path: string, message: string}>}
     */
    public function forFields(array $fields, array $input): array
    {
        $input = self::cleaned($this->rich(), $input) + $input;
        $issues = [];
        foreach (array_keys($input) as $key) {
            if (! in_array($key, $fields, true)) {
                $issues[] = ['path' => (string) $key, 'message' => 'Unknown field.'];
            }
        }
        $validator = Validation::make($input, array_intersect_key(self::withoutTrashed($this->rules()), array_flip($fields)));
        if ($validator->fails()) {
            self::explainTrashed($validator, $input);
            array_push($issues, ...FlareErrors::issues($validator->errors()->toArray()));
        }
        if ($issues !== []) {
            return ['values' => [], 'issues' => $issues];
        }

        $validated = $validator->validated();
        $defaults = $this->defaults();
        $values = [];
        foreach ($this->columns() as $field => $column) {
            if (array_key_exists($field, $validated)) {
                $values[$column] = $validated[$field];
            } elseif (array_key_exists($field, $defaults)) {
                $values[$column] = $defaults[$field];
            }
        }

        return ['values' => $this->make($values, true), 'issues' => []];
    }

    /**
     * Rich text is cleaned before anything looks at it: what is checked for being there,
     * and for being short enough, is the HTML that would be stored.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(self::cleaned($this->rich(), $this->all()));
    }

    /**
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> The fields that were sent as text, cleaned. One with nothing left to read is empty.
     */
    private static function cleaned(array $fields, array $input): array
    {
        $clean = [];
        foreach ($fields as $field) {
            if (is_string($input[$field] ?? null)) {
                $html = Html::clean($input[$field]);
                $clean[$field] = $html === '' ? null : $html;
            }
        }

        return $clean;
    }

    /** Tables known to have a trash. Only "yes" is remembered: the column can arrive with a migration. */
    private static array $trashes = [];

    protected function validationRules()
    {
        $rules = self::withoutTrashed(parent::validationRules());
        if (! $this->isMethod('PATCH')) {
            return $rules;
        }

        return array_map(fn ($rule) => ['sometimes', ...(is_array($rule) ? $rule : explode('|', $rule))], $rules);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $known = array_keys($this->columns());
            // A file field is sent back with its details beside it (imageFile for image);
            // a client that echoes a record shouldn't be refused for it.
            $computed = array_map(fn (string $field) => $field.'File', $known);
            foreach (array_keys($this->json()->all() ?: $this->request->all()) as $key) {
                if (! in_array($key, $known, true) && ! in_array($key, self::READ_ONLY, true) && ! in_array($key, $computed, true)) {
                    $validator->errors()->add((string) $key, 'Unknown field.');
                }
            }
            self::explainTrashed($validator, $validator->getData());
        });
    }

    /**
     * A relation points at a record that is there, and one in the trash is not: nothing
     * lists it and its own page answers 404. Laravel's exists rule looks at the table,
     * where a deleted record still is, so it is told to leave those out.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private static function withoutTrashed(array $rules): array
    {
        foreach ($rules as $set) {
            foreach (is_array($set) ? $set : [] as $rule) {
                if (! $rule instanceof Exists) {
                    continue;
                }
                // "exists:categories,id": the rule keeps its table to itself.
                $table = (string) strtok(substr((string) $rule, strlen('exists:')), ',');
                if (self::$trashes[$table] ?? (Schema::hasColumn($table, 'deleted_at') && (self::$trashes[$table] = true))) {
                    $rule->withoutTrashed();
                }
            }
        }

        return $rules;
    }

    /**
     * A unique value can be held by a record nobody can see: one in the trash. "Already
     * taken" would send someone looking through a list it isn't in, so say where it is.
     * The same goes for a relation to a record that is in the trash.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{path: string, message: string}> The messages that were replaced
     */
    private static function explainTrashed(Validator $validator, array $data): array
    {
        $replaced = [];
        foreach ($validator->failed() as $field => $rules) {
            // What Laravel gives for a failed unique rule: the table and the column it looked in.
            [$table, $column] = array_pad($rules['Unique'] ?? [], 2, null);
            $value = $data[$field] ?? null;
            if (! is_string($table) || ! is_string($column) || ! is_scalar($value) || $column === 'NULL') {
                continue;
            }
            if (! Schema::hasColumn($table, 'deleted_at') || ! DB::table($table)->where($column, $value)->whereNotNull('deleted_at')->exists()) {
                continue;
            }
            $message = 'This belongs to a record in the trash. Restore that record, or delete it for good to use the value again.';
            $validator->errors()->forget($field);
            $validator->errors()->add($field, $message);
            $replaced[] = ['path' => (string) $field, 'message' => $message];
        }
        foreach ($validator->failed() as $field => $rules) {
            // And for a failed exists rule: the table and the column, then what else it asked.
            [$table, $column] = array_pad($rules['Exists'] ?? [], 2, null);
            $value = $data[$field] ?? null;
            if (! is_string($table) || ! is_string($column) || ! is_scalar($value) || ! (self::$trashes[$table] ?? false)) {
                continue;
            }
            try {
                $trashed = DB::table($table)->where($column, $value)->whereNotNull('deleted_at')->exists();
            } catch (\Throwable) {
                continue; // not something that could be an id at all
            }
            if ($trashed) {
                $message = 'This one is in the trash. Restore it, or choose another.';
                $validator->errors()->forget($field);
                $validator->errors()->add($field, $message);
                $replaced[] = ['path' => (string) $field, 'message' => $message];
            }
        }

        return $replaced;
    }

    /**
     * Many rows, each checked by the rules for creating one.
     *
     * The same rules as a single create, on purpose: a second way into the table with its
     * own idea of what is valid would let through a row the form then refuses to save.
     * Rows are numbered from 1, as a person counts them.
     *
     * Two rows of one batch can't share a unique value either. The database would refuse
     * the second, but only after the first was written and with no row number to show.
     *
     * @param  list<mixed>  $rows
     * @return array{values: list<array<string, mixed>>, problems: list<array{row: int, issues: list<array{path: string, message: string}>}>}
     */
    public function many(array $rows): array
    {
        $rules = self::withoutTrashed($this->rules());
        $columns = $this->columns();
        $defaults = $this->defaults();
        $known = array_keys($columns);
        $computed = array_map(fn (string $field) => $field.'File', $known);
        $unique = array_keys(array_filter($rules, fn ($rule) => is_array($rule) && array_filter($rule, fn ($one) => $one instanceof Unique) !== []));

        $values = [];
        $problems = [];
        $taken = [];
        $made = [];
        foreach (array_values($rows) as $index => $row) {
            $number = $index + 1;
            if (! is_array($row) || array_is_list($row)) {
                // An empty JSON object arrives as an empty list; anything else here is not fields at all.
                $problems[] = ['row' => $number, 'issues' => [['path' => '', 'message' => $row === [] ? 'This row is empty.' : 'This row is not a record.']]];

                continue;
            }
            $issues = [];
            foreach (array_keys($row) as $key) {
                if (! in_array($key, $known, true) && ! in_array($key, self::READ_ONLY, true) && ! in_array($key, $computed, true)) {
                    $issues[] = ['path' => (string) $key, 'message' => 'Unknown field.'];
                }
            }
            $row = self::cleaned($this->rich(), $row) + $row;
            $validator = Validation::make($row, $rules);
            if ($validator->fails()) {
                self::explainTrashed($validator, $row);
                array_push($issues, ...FlareErrors::issues($validator->errors()->toArray()));
            }
            foreach ($unique as $field) {
                $value = $row[$field] ?? null;
                if (! is_scalar($value) || (string) $value === '') {
                    continue;
                }
                $mark = $field."\0".$value;
                if (isset($taken[$mark])) {
                    $issues[] = ['path' => $field, 'message' => "The same as row {$taken[$mark]}. Each one needs its own."];
                } else {
                    $taken[$mark] = $number;
                }
            }
            if ($issues !== []) {
                $problems[] = ['row' => $number, 'issues' => $issues];

                continue;
            }

            $validated = $validator->validated();
            $mapped = [];
            foreach ($columns as $field => $column) {
                if (array_key_exists($field, $validated)) {
                    $mapped[$column] = $validated[$field];
                } elseif (array_key_exists($field, $defaults)) {
                    $mapped[$column] = $defaults[$field];
                }
            }
            // Two rows with one title get two slugs: what was made for an earlier row is remembered.
            $values[] = $this->make($mapped, true, $made);
        }

        return ['values' => $values, 'problems' => $problems];
    }

    /** Validated input keyed by column, ready for create()/update(). @return array<string, mixed> */
    public function values(): array
    {
        $validated = $this->validated();
        $defaults = $this->defaults();
        $values = [];
        foreach ($this->columns() as $field => $column) {
            if (array_key_exists($field, $validated)) {
                $values[$column] = $validated[$field];
            } elseif (array_key_exists($field, $defaults) && ! $this->isMethod('PATCH')) {
                // Left out of a create, or of a replace, it is what it usually is.
                $values[$column] = $defaults[$field];
            } elseif ($this->isMethod('PUT')) {
                $values[$column] = null;
            }
        }

        return $this->make($values, $this->isMethod('POST'));
    }
}
