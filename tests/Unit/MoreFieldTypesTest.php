<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Countries;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Fake;
use Nevela\Laravel\Support\Field;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoreFieldTypesTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function domains(): iterable
    {
        yield 'as it should be' => ['example.com', 'example.com'];
        yield 'in capitals' => ['Example.COM', 'example.com'];
        yield 'a whole address' => ['https://Example.COM/about?x=1#top', 'example.com'];
        yield 'with a port' => ['http://example.com:8080/', 'example.com'];
        yield 'with someone in it' => ['https://ada:secret@example.com/', 'example.com'];
        yield 'with a dot at the end' => ['example.com.', 'example.com'];
        yield 'a subdomain' => ['shop.example.co.ug', 'shop.example.co.ug'];
    }

    public function test_an_international_domain_is_kept_as_punycode_where_php_can_write_it(): void
    {
        if (! function_exists('idn_to_ascii')) {
            $this->assertSame('münchen.de', Field::inOneForm('domain', 'münchen.de'));
            $this->assertSame(0, preg_match(Field::DOMAIN_PATTERN, 'münchen.de'), 'and refused, rather than kept as it is');

            return;
        }
        $this->assertSame('xn--mnchen-3ya.de', Field::inOneForm('domain', 'MÜNCHEN.de'));
    }

    #[DataProvider('domains')]
    public function test_a_domain_is_kept_as_its_bare_host(string $written, string $kept): void
    {
        $host = Field::inOneForm('domain', $written);

        $this->assertSame($kept, $host);
        $this->assertSame(1, preg_match(Field::DOMAIN_PATTERN, $host));
    }

    public function test_what_is_not_a_domain_does_not_pass_as_one(): void
    {
        foreach (['localhost', 'no spaces.com', '-bad.com', 'bad-.com', 'a..b.com', 'example.1', '.com', ''] as $bad) {
            $this->assertSame(0, preg_match(Field::DOMAIN_PATTERN, Field::inOneForm('domain', $bad)), "{$bad} passed");
        }
    }

    public function test_a_time_is_kept_in_five_characters(): void
    {
        $this->assertSame('09:05', Field::inOneForm('time', '9:05'));
        $this->assertSame('09:05', Field::inOneForm('time', '09:05:30'));
        $this->assertSame('23:59', Field::inOneForm('time', '23:59'));
        foreach (['24:00', '9:60', 'noon', '9', '09:05:99', '09:05:60'] as $bad) {
            $this->assertSame(0, preg_match(Field::TIME_PATTERN, Field::inOneForm('time', $bad)), "{$bad} passed");
        }
    }

    public function test_a_country_is_its_code_in_capitals_and_can_have_a_default(): void
    {
        $this->assertSame('UG', Field::inOneForm('country', ' ug '));
        $this->assertCount(249, Countries::CODES);
        $this->assertCount(249, array_unique(Countries::CODES));

        foreach (['land:country(UG)', 'land:country:UG', 'land:country:ug', 'land:country=UG'] as $written) {
            $this->assertSame('UG', Field::parse($written)->default, $written);
        }
        $this->assertNull(Field::parse('land:country')->default);
    }

    public function test_a_default_has_to_be_a_country(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"XX" isn\'t one');

        Field::parse('land:country:XX');
    }

    public function test_json_cannot_be_unique_searched_sorted_or_filtered_or_part_of_a_set(): void
    {
        $extra = Field::parse('extra:json');
        $this->assertFalse($extra->searchable());
        $this->assertFalse($extra->sortable());
        $this->assertFalse($extra->filterable());
        $this->assertSame('hidden', $extra->listing());

        try {
            Field::parse('extra:json!');
            $this->fail('a unique json field was accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("can't be unique", $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        Descriptor::fromArray(['name' => 'Place', 'fields' => ['a' => ['kind' => 'string'], 'b' => ['kind' => 'json']], 'unique' => [['a', 'b']]]);
    }

    public function test_they_are_read_back_from_a_descriptor(): void
    {
        $place = Descriptor::fromSpec('Place', 'site:domain!, land:country(UG), opens:time?, extra:json?');

        $this->assertEquals($place, Descriptor::fromJson($place->toJson()));
    }

    public function test_what_is_generated(): void
    {
        $place = Descriptor::fromSpec('Place', 'site:domain!, land:country(UG), opens:time?, extra:json?');
        $generator = new ResourceGenerator;

        $migration = $generator->migration($place);
        $this->assertStringContainsString("\$table->string('site', 253)->unique();", $migration);
        $this->assertStringContainsString("\$table->string('land', 2)->default('UG');", $migration);
        $this->assertStringContainsString("\$table->string('opens', 5)->nullable();", $migration);
        $this->assertStringContainsString("\$table->json('extra')->nullable();", $migration);

        $request = $generator->request($place, [$place]);
        $this->assertStringContainsString("return ['site' => 'domain', 'land' => 'country', 'opens' => 'time', 'extra' => 'json'];", $request);
        $this->assertStringContainsString('Rule::in(\\Nevela\\Laravel\\Support\\Countries::CODES)', $request);
        $this->assertStringContainsString("'extra' => ['nullable', 'array']", $request);

        $this->assertStringContainsString("'extra' => 'array',", $generator->model($place));

        $typescript = $generator->typescript($place, [$place]);
        $this->assertStringContainsString('site: field.domain(', $typescript);
        $this->assertStringContainsString('land: field.country(', $typescript);
        $this->assertMatchesRegularExpression('/opens: field\.string\(\{.*time: true/', $typescript);
        $this->assertStringContainsString('extra: field.json(', $typescript);
    }

    /** @return iterable<string, array{string}> */
    public static function lengths(): iterable
    {
        yield 'a domain' => ['site:domain(10)'];
        yield 'a country' => ['land:country(3)'];
        yield 'a time' => ['opens:time(3)'];
    }

    #[DataProvider('lengths')]
    public function test_a_length_means_nothing_to_these_and_is_refused(string $written): void
    {
        if ($written === 'land:country(3)') {
            // "3" is no country, which is how a country reads what is in its brackets.
            $this->expectExceptionMessage('"3" isn\'t one');
        } else {
            $this->expectExceptionMessage('means nothing to one');
        }

        Field::parse($written);
    }

    public function test_a_descriptor_can_not_give_one_a_length_either(): void
    {
        foreach (['domain', 'country', 'time'] as $format) {
            try {
                Field::fromArray('field', ['kind' => 'string', 'format' => $format, 'maxLength' => 10]);
                $this->fail("{$format} took a length");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('has a length of its own', $e->getMessage());
            }
        }
    }

    public function test_a_field_is_seeded_by_its_format_before_its_name(): void
    {
        $place = Descriptor::fromSpec('Place', 'website:domain, emailDomain:domain, phoneLand:country, emailAt:time');

        foreach (range(1, 20) as $n) {
            $row = Fake::row($place, $n);
            $this->assertSame(1, preg_match(Field::DOMAIN_PATTERN, $row['website']), $row['website']);
            $this->assertSame(1, preg_match(Field::DOMAIN_PATTERN, $row['email_domain']), $row['email_domain']);
            $this->assertContains($row['phone_land'], Countries::CODES);
            $this->assertSame(1, preg_match(Field::TIME_PATTERN, $row['email_at']), $row['email_at']);
        }
    }

    public function test_seeded_values_are_what_their_rules_ask_for_and_unique_ones_do_not_repeat(): void
    {
        $place = Descriptor::fromSpec('Place', 'site:domain!, plain:domain, land:country, only:country!, opens:time, slot:time!, extra:json');
        $seen = ['site' => [], 'only' => [], 'slot' => []];
        foreach (range(1, 240) as $n) {
            $row = Fake::row($place, $n);
            $this->assertSame(1, preg_match(Field::DOMAIN_PATTERN, $row['site']), $row['site']);
            $this->assertSame(1, preg_match(Field::DOMAIN_PATTERN, $row['plain']), $row['plain']);
            $this->assertContains($row['land'], Countries::CODES);
            $this->assertContains($row['only'], Countries::CODES);
            $this->assertSame(1, preg_match(Field::TIME_PATTERN, $row['opens']), $row['opens']);
            $this->assertSame(1, preg_match(Field::TIME_PATTERN, $row['slot']), $row['slot']);
            $this->assertIsArray(json_decode($row['extra'], true));
            foreach (array_keys($seen) as $column) {
                $this->assertArrayNotHasKey($row[$column], $seen[$column], "{$column} repeated at {$n}");
                $seen[$column][$row[$column]] = true;
            }
        }

        $this->assertSame(249, Fake::capacity(Field::parse('only:country!')));
        $this->assertSame(1440, Fake::capacity(Field::parse('slot:time!')));
    }
}
