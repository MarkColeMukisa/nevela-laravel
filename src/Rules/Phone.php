<?php

namespace Nevela\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Nevela\Laravel\Support\Phone as Numbers;

/**
 * A phone field has to hold a number: one of its country's, for phone:tel(UG), or anyone's.
 * The request has put a good one in the form it is kept in already; this says what is wrong
 * with the rest.
 */
final class Phone implements ValidationRule
{
    public function __construct(private readonly ?string $country = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $assume = self::assumed();
        if (! is_string($value) || Numbers::normalise($value, $this->country, $assume) === null) {
            $fail(Numbers::message($this->country, $assume));
        }
    }

    /** NEVELA_PHONE_COUNTRY: where a number without a + is from. */
    public static function assumed(): ?string
    {
        return config('nevela.phone_country') ?: null;
    }
}
