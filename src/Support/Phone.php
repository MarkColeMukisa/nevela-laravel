<?php

namespace Nevela\Laravel\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * Phone numbers, checked against libphonenumber: Google's record of how numbers look in
 * each country, which the dashboard's check reads too.
 *
 * A number is taken the ways people write it (0772 123456, 772123456, 256772123456,
 * 00256 772 123 456, +256 772 123 456) and kept the one way: +256772123456.
 *
 * phone:tel(UG) takes that country's numbers only. phone:tel takes any country's, and reads
 * one without a + as a number of NEVELA_PHONE_COUNTRY, if the app says.
 */
final class Phone
{
    /**
     * What a number of these countries is called, and how one is written there, for what
     * is said about one that isn't. Any other country is named by its code.
     *
     * @var array<string, array{name: string, example: string}>
     */
    private const NAMED = [
        'UG' => ['name' => 'Ugandan', 'example' => '0772 123456'],
        'KE' => ['name' => 'Kenyan', 'example' => '0712 345678'],
        'TZ' => ['name' => 'Tanzanian', 'example' => '0712 345678'],
        'RW' => ['name' => 'Rwandan', 'example' => '0788 123456'],
    ];

    /**
     * Where the seeder's numbers come from when any country's will do: a calling code, what
     * starts a mobile number there, and how many digits follow. Each was measured: whatever
     * digits follow, the number is real, which a shorter start such as France's 6 isn't.
     *
     * @var list<array{string, string, int}>
     */
    private const SEEDED = [
        ['256', '77', 7], ['254', '71', 7], ['255', '71', 7], ['250', '78', 7], ['234', '803', 7],
        ['233', '24', 7], ['27', '82', 7], ['44', '74', 8], ['49', '151', 8], ['33', '61', 7],
        ['91', '98', 8], ['34', '6', 8], ['39', '33', 8], ['971', '50', 7], ['966', '50', 7],
        ['20', '10', 8], ['212', '61', 7], ['31', '61', 7], ['61', '41', 7], ['65', '91', 6],
    ];

    public static function known(string $country): bool
    {
        return in_array($country, PhoneNumberUtil::getInstance()->getSupportedRegions(), true) && self::sample($country) !== null;
    }

    /**
     * The number as +256772123456, or null when it isn't one.
     *
     * @param  string|null  $country  It has to be a number of this country.
     * @param  string|null  $assume  Otherwise, where a number without a + is from. With neither, it needs a +.
     */
    public static function normalise(string $value, ?string $country = null, ?string $assume = null): ?string
    {
        $phones = PhoneNumberUtil::getInstance();
        // Spaces, dashes, dots and brackets are how it was written, not the number, and 00 is
        // how a + is dialled in most of the world.
        $number = (string) preg_replace('/[\s\-.()\x{00A0}]/u', '', $value);
        if (str_starts_with($number, '00')) {
            $number = '+'.substr($number, 2);
        }
        try {
            $parsed = $phones->parse($number, $country ?? $assume);
        } catch (NumberParseException) {
            return null;
        }
        // An extension has nowhere to go in +256772123456, and would be lost without a word.
        if ($parsed->hasExtension() || ! ($country === null ? $phones->isValidNumber($parsed) : $phones->isValidNumberForRegion($parsed, $country))) {
            return null;
        }

        return $phones->format($parsed, PhoneNumberFormat::E164);
    }

    /** One such number as it is kept, to show what is meant: +256772123456. */
    public static function example(string $country): string
    {
        return isset(self::NAMED[$country])
            ? (string) self::normalise(self::NAMED[$country]['example'], $country)
            : self::written($country, PhoneNumberFormat::E164);
    }

    /**
     * What to say when a number isn't one: "Enter a Ugandan number, such as 0772 123456 or +256 772 123456."
     *
     * @param  string|null  $assume  For a field of any country: where a number without a + is from.
     */
    public static function message(?string $country = null, ?string $assume = null): string
    {
        if ($country === null && $assume === null) {
            return 'Enter a valid phone number, with its country code, such as '.self::written('UG', PhoneNumberFormat::INTERNATIONAL).'.';
        }
        $from = (string) ($country ?? $assume);
        $local = self::NAMED[$from]['example'] ?? self::written($from, PhoneNumberFormat::NATIONAL);
        // +256 772 123456 is how it is said here, whichever way the library spaces it.
        $international = isset(self::NAMED[$from])
            ? '+'.PhoneNumberUtil::getInstance()->getCountryCodeForRegion($from).' '.substr($local, 1)
            : self::written($from, PhoneNumberFormat::INTERNATIONAL);
        if ($country === null) {
            return "Enter a valid phone number, such as {$local} or {$international}. One from another country starts with its code.";
        }
        $what = isset(self::NAMED[$from]) ? 'a '.self::NAMED[$from]['name'].' number' : "a number from {$from}";

        return "Enter {$what}, such as {$local} or {$international}.";
    }

    /**
     * A made-up number that is a real one, for the seeder: of that country or, with none,
     * from around the world. The nth of them when it has to be unique, or any.
     */
    public static function fake(?string $country, int $number, bool $unique): string
    {
        if ($country === null) {
            [$code, $lead, $digits] = self::SEEDED[$number % count(self::SEEDED)];

            return "+{$code}{$lead}".self::tail(intdiv($number, count(self::SEEDED)), $digits, $unique);
        }
        $example = self::example($country);
        $code = (string) PhoneNumberUtil::getInstance()->getCountryCodeForRegion($country);
        $national = substr($example, strlen($code) + 1);
        // It starts as the example does, so it reads as one from there, and with as little of
        // it as gives a real number. The US's 20 can't, and its 2015 can't, and 201555 can.
        for ($kept = 2; $kept < strlen($national); $kept++) {
            $made = "+{$code}".substr($national, 0, $kept).self::tail($number, strlen($national) - $kept, $unique);
            if (self::normalise($made, $country) === $made) {
                return $made;
            }
        }

        return $example;
    }

    private static function tail(int $number, int $digits, bool $unique): string
    {
        return $unique
            ? str_pad((string) ($number % 10 ** $digits), $digits, '0', STR_PAD_LEFT)
            : implode('', array_map(fn () => (string) mt_rand(0, 9), range(1, $digits)));
    }

    private static function written(string $country, PhoneNumberFormat|int $format): string
    {
        $sample = self::sample($country);

        return $sample === null ? '' : PhoneNumberUtil::getInstance()->format($sample, $format);
    }

    /**
     * A number of that country, by the country's own rules. Where it shares its calling code,
     * as the Vatican does with Italy, the library's example can be its neighbour's.
     */
    private static function sample(string $country): ?PhoneNumber
    {
        $phones = PhoneNumberUtil::getInstance();
        foreach ([PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE, PhoneNumberType::FIXED_LINE_OR_MOBILE] as $type) {
            $example = $phones->getExampleNumberForType($country, $type);
            if ($example !== null && $phones->isValidNumberForRegion($example, $country)) {
                return $example;
            }
        }
        $example = $phones->getExampleNumber($country);

        return $example !== null && $phones->isValidNumberForRegion($example, $country) ? $example : null;
    }
}
