<?php

namespace Nevela\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Nevela\Laravel\Models\Upload;

/**
 * A file field's value has to be the key of something uploaded to that same field.
 * Without this, anyone who can edit a record could point it at another resource's file.
 */
final class UploadKey implements ValidationRule
{
    public function __construct(private readonly string $resource, private readonly string $field) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }
        $known = Upload::query()->where('key', $value)->where('resource', $this->resource)->where('field', $this->field)->exists();
        if (! $known) {
            $fail('Upload the file again: this one was not uploaded to this field.');
        }
    }
}
