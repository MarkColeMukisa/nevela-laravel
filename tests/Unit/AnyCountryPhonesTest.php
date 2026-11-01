<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Fake;
use Nevela\Laravel\Support\Field;
use Nevela\Laravel\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnyCountryPhonesTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, ?string, ?string}> */
    public static function numbers(): iterable
    {
        yield 'with its plus' => ['+256 772 123 456', null, null, '+256772123456'];
        yield 'with 00 for its plus' => ['00256 772 123 456', null, null, '+256772123456'];
        yield 'another country' => ['+44 20 7946 0958', null, null, '+442079460958'];
        yield 'no plus and nowhere to read it from' => ['0772 123456', null, null, null];
        yield 'no plus, read as the app\'s' => ['0772 123456', null, 'UG', '+256772123456'];
        yield 'a plus says otherwise' => ['+44 7400 123456', null, 'UG', '+447400123456'];
        yield 'too short' => ['+256 12', null, null, null];
        yield 'not a number' => ['blue', null, 'UG', null];
        yield 'empty' => ['', null, 'UG', null];
        yield 'held to a country' => ['07400 123456', 'GB', null, '+447400123456'];
        yield 'held to a country, and from another' => ['+256 772 123 456', 'GB', null, null];
        yield 'the held country wins over the app\'s' => ['07400 123456', 'GB', 'UG', '+447400123456'];
    }

    #[DataProvider('numbers')]
    public function test_a_number_is_read_as_it_was_written_and_kept_one_way(string $value, ?string $country, ?string $assume, ?string $kept): void
    {
        $this->assertSame($kept, Phone::normalise($value, $country, $assume));
    }

    public function test_any_country_can_hold_a_field(): void
    {
        foreach (['GB', 'US', 'NG', 'DE'] as $country) {
            $this->assertSame($country, Field::parse("phone:tel({$country})")->country);
            $this->assertNotNull(Phone::normalise(Phone::example($country), $country), "{$country}'s example");
        }

        $this->expectException(InvalidArgumentException::class);
        Field::parse('phone:tel(XX)');
    }

    public function test_what_is_said_of_a_number_that_isn_t_one_says_what_one_is(): void
    {
        $this->assertSame('Enter a valid phone number, with its country code, such as +256 712 345678.', Phone::message());
        $this->assertSame('Enter a valid phone number, such as 0772 123456 or +256 772 123456. One from another country starts with its code.', Phone::message(null, 'UG'));
        $this->assertSame('Enter a Ugandan number, such as 0772 123456 or +256 772 123456.', Phone::message('UG'));
        $this->assertSame('Enter a number from GB, such as 07400 123456 or +44 7400 123456.', Phone::message('GB'));
    }

    public function test_seeded_numbers_are_real_and_unique_ones_do_not_repeat(): void
    {
        $seen = [];
        foreach (range(1, 4000) as $n) {
            $number = Phone::fake(null, $n, true);
            $this->assertSame($number, Phone::normalise($number), "{$number} isn't a real number");
            $this->assertArrayNotHasKey($number, $seen);
            $seen[$number] = true;
        }
        $this->assertGreaterThanOrEqual(15, count(array_unique(array_map(fn (string $number) => substr($number, 0, 3), array_keys($seen)))), 'from around the world');

        foreach (range(1, 200) as $n) {
            $any = Phone::fake(null, $n, false);
            $this->assertSame($any, Phone::normalise($any), "{$any} isn't a real number");
        }
    }

    public function test_a_million_seeded_numbers_do_not_repeat(): void
    {
        $count = 0;
        $seen = [];
        foreach (range(1, 1000000, 997) as $n) {
            $seen[Phone::fake(null, $n, true)] = true;
            $count++;
        }

        $this->assertCount($count, $seen);
    }

    public function test_seeded_numbers_of_a_country_are_that_country_s(): void
    {
        foreach (['UG', 'KE', 'TZ', 'RW', 'GB', 'NG'] as $country) {
            foreach ([1, 77, 4000] as $n) {
                $number = Phone::fake($country, $n, true);
                $this->assertSame($number, Phone::normalise($number, $country), "{$number} isn't {$country}'s");
            }
        }
    }

    public function test_a_field_seeds_a_real_number_whichever_way_it_says_it_is_a_phone(): void
    {
        $caller = Descriptor::fromSpec('Caller', 'phone:tel!, mobile:tel, phoneNumber:string');

        foreach (range(1, 40) as $n) {
            $row = Fake::row($caller, $n);
            foreach (['phone', 'mobile', 'phone_number'] as $column) {
                $this->assertSame($row[$column], Phone::normalise($row[$column]), "{$column} {$row[$column]}");
            }
        }
    }

    public function test_what_is_generated(): void
    {
        $caller = Descriptor::fromSpec('Caller', 'phone:tel!, office:tel(GB)?');
        $request = (new ResourceGenerator)->request($caller, [$caller]);

        $this->assertStringContainsString("return ['phone' => NULL, 'office' => 'GB'];", $request);
        $this->assertStringContainsString('new \\Nevela\\Laravel\\Rules\\Phone,', $request);
        $this->assertStringContainsString("new \\Nevela\\Laravel\\Rules\\Phone('GB')", $request);
    }

    public function test_the_dashboard_s_picker_starts_at_the_app_s_country_where_it_says_one(): void
    {
        $caller = Descriptor::fromSpec('Caller', 'phone:tel, office:tel(GB)');

        $this->assertStringNotContainsString('startsAt', (new ResourceGenerator)->typescript($caller, [$caller]));

        $typescript = (new ResourceGenerator(null, 'UG'))->typescript($caller, [$caller]);
        $this->assertMatchesRegularExpression('/phone: field\.tel\(\{.*startsAt: "UG"/', $typescript);
        $this->assertDoesNotMatchRegularExpression('/office: field\.tel\(\{.*startsAt/', $typescript);
    }

    public function test_the_app_s_country_has_to_be_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NEVELA_PHONE_COUNTRY is "Uganda"');

        new ResourceGenerator(null, 'Uganda');
    }
}
