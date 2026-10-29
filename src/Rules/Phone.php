<?php

namespace Nevela\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Nevela\Laravel\Support\Phone as Numbers;

/**
 * A phone field held to one country, phone:tel(UG), has to be a number there. The request
 * has put a good one in the form it is kept in already; this says what is wrong with the rest.
 */
final class Phone implements ValidationRule
{
    public function __construct(private readonly string $country) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Numbers::normalise($value, $this->country) === null) {
            $fail(Numbers::message($this->country));
        }
    }
}
