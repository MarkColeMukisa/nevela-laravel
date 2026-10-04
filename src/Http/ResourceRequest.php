<?php

namespace Nevela\Laravel\Http;

use Illuminate\Foundation\Http\FormRequest;
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
