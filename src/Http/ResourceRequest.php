<?php

namespace Nevela\Laravel\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator as Validation;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

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

    protected function validationRules()
    {
        $rules = parent::validationRules();
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
        });
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
        $rules = $this->rules();
        $columns = $this->columns();
        $known = array_keys($columns);
        $computed = array_map(fn (string $field) => $field.'File', $known);
        $unique = array_keys(array_filter($rules, fn ($rule) => is_array($rule) && array_filter($rule, fn ($one) => $one instanceof Unique) !== []));

        $values = [];
        $problems = [];
        $taken = [];
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
            $validator = Validation::make($row, $rules);
            if ($validator->fails()) {
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
                }
            }
            $values[] = $mapped;
        }

        return ['values' => $values, 'problems' => $problems];
    }

    /** Validated input keyed by column, ready for create()/update(). @return array<string, mixed> */
    public function values(): array
    {
        $validated = $this->validated();
        $values = [];
        foreach ($this->columns() as $field => $column) {
            if (array_key_exists($field, $validated)) {
                $values[$column] = $validated[$field];
            } elseif ($this->isMethod('PUT')) {
                $values[$column] = null;
            }
        }

        return $values;
    }
}
