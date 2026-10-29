<?php

namespace Nevela\Laravel\Support;

/**
 * Phone numbers of one country, for a field written phone:tel(UG).
 *
 * A number is taken the ways people write it there (0772 123456, 772123456, 256772123456,
 * +256 772 123 456) and kept the one way: +256772123456. A plain tel field takes any
 * country's number and is left as it was sent.
 */
final class Phone
{
    /**
     * The countries a field can be held to: the calling code, how many digits come after
     * it, the 0 dialled before them at home, what a number there is called, and one
     * written the local way.
     *
     * @var array<string, array{code: string, digits: int, trunk: string, name: string, example: string}>
     */
    public const COUNTRIES = [
        'UG' => ['code' => '256', 'digits' => 9, 'trunk' => '0', 'name' => 'Ugandan', 'example' => '0772 123456'],
        'KE' => ['code' => '254', 'digits' => 9, 'trunk' => '0', 'name' => 'Kenyan', 'example' => '0712 345678'],
        'TZ' => ['code' => '255', 'digits' => 9, 'trunk' => '0', 'name' => 'Tanzanian', 'example' => '0712 345678'],
        'RW' => ['code' => '250', 'digits' => 9, 'trunk' => '0', 'name' => 'Rwandan', 'example' => '0788 123456'],
    ];

    public static function known(string $country): bool
    {
        return isset(self::COUNTRIES[$country]);
    }

    /** The number as +256772123456, or null when it isn't one of that country's. */
    public static function normalise(string $value, string $country): ?string
    {
        ['code' => $code, 'digits' => $digits, 'trunk' => $trunk] = self::COUNTRIES[$country];
        // Spaces, dashes, dots and brackets are how it was written, not the number.
        $number = (string) preg_replace('/[\s\-.()\x{00A0}]/u', '', $value);
        $number = match (true) {
            str_starts_with($number, '+') => str_starts_with($number, "+{$code}") ? substr($number, strlen($code) + 1) : null,
            str_starts_with($number, "00{$code}") => substr($number, strlen($code) + 2),
            str_starts_with($number, $code) && strlen($number) === strlen($code) + $digits => substr($number, strlen($code)),
            $trunk !== '' && str_starts_with($number, $trunk) && strlen($number) === $digits + strlen($trunk) => substr($number, strlen($trunk)),
            default => $number,
        };
        if ($number === null || ! ctype_digit($number) || strlen($number) !== $digits || $number[0] === '0') {
            return null;
        }

        return "+{$code}{$number}";
    }

    /** What to say when a number isn't one: "Enter a Ugandan number, such as 0772 123456 or +256 772 123456." */
    public static function message(string $country): string
    {
        ['code' => $code, 'trunk' => $trunk, 'name' => $name, 'example' => $example] = self::COUNTRIES[$country];
        $international = "+{$code} ".substr($example, strlen($trunk));

        return "Enter a {$name} number, such as {$example} or {$international}.";
    }

    /**
     * A made-up number of that country, for the seeder: the nth of them when it has to be
     * unique, or any. It starts as the example does, so it reads as one from there.
     */
    public static function fake(string $country, int $number, bool $unique): string
    {
        ['code' => $code, 'digits' => $digits, 'trunk' => $trunk, 'example' => $example] = self::COUNTRIES[$country];
        $lead = substr(str_replace(' ', '', $example), strlen($trunk), 2);
        $rest = $digits - strlen($lead);
        $tail = $unique
            ? str_pad((string) ($number % 10 ** $rest), $rest, '0', STR_PAD_LEFT)
            : implode('', array_map(fn () => (string) mt_rand(0, 9), range(1, $rest)));

        return "+{$code}{$lead}{$tail}";
    }
}
