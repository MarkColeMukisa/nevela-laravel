<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Fake;
use Nevela\Laravel\Support\Field;
use PHPUnit\Framework\TestCase;

final class ValuesInOneFormTest extends TestCase
{
    public function test_a_value_in_its_one_form(): void
    {
        $this->assertSame('ada@example.com', Field::inOneForm('email', ' Ada@Example.COM '));
        $this->assertSame('émile@example.com', Field::inOneForm('email', 'ÉMILE@Example.com'));
        $this->assertSame('#aabbcc', Field::inOneForm('color', '#ABC'));
        $this->assertSame('#abcdef', Field::inOneForm('color', '#ABCDEF'));
        $this->assertSame('blue', Field::inOneForm('color', 'BLUE'), 'left for the rule to refuse');
    }

    public function test_a_rating_says_how_many_stars_and_grit_can_too(): void
    {
        $this->assertSame(5, Field::parse('stars:rating')->mostStars());
        $this->assertSame(10, Field::parse('stars:rating(10)')->mostStars());
        $this->assertSame(10, Field::parse('stars:rating:10')->mostStars());
        $this->assertSame(7, Field::parse('stars:rating(7)?')->mostStars());
        $this->assertNull(Field::parse('stars:rating')->max, 'five is what no number means');
    }

    public function test_it_is_written_to_the_descriptor_and_read_back(): void
    {
        $this->assertSame(10, Field::fromArray('stars', Field::parse('stars:rating(10)')->toArray())->max);
        $this->assertArrayNotHasKey('max', Field::parse('stars:rating')->toArray());
    }

    public function test_only_a_rating_has_a_most_and_only_from_two_to_ten(): void
    {
        foreach (['stars:rating(1)', 'stars:rating(11)', 'stars:rating(0)'] as $written) {
            try {
                Field::parse($written);
                $this->fail("{$written} was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('from 2 to 10 stars', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        Field::fromArray('age', ['kind' => 'int', 'max' => 10]);
    }

    public function test_what_is_generated(): void
    {
        $supplier = Descriptor::fromSpec('Supplier', 'name:string, contact:email, site:url, tint:color, stars:rating, score:rating(10)');
        $generator = new ResourceGenerator;
        $request = $generator->request($supplier, [$supplier]);

        $this->assertStringContainsString("return ['contact' => 'email', 'tint' => 'color'];", $request);
        $this->assertStringContainsString("'url:http,https'", $request);
        $this->assertStringContainsString("'stars' => ['required', 'integer', 'between:1,5']", $request);
        $this->assertStringContainsString("'score' => ['required', 'integer', 'between:1,10']", $request);

        $typescript = $generator->typescript($supplier, [$supplier]);
        $this->assertMatchesRegularExpression('/score: field\.int\(\{[^}]*max: 10/', $typescript);
        $this->assertDoesNotMatchRegularExpression('/stars: field\.int\(\{[^}]*max:/', $typescript);
    }

    public function test_a_resource_with_nothing_to_keep_has_no_such_method(): void
    {
        $plain = Descriptor::fromSpec('Note', 'body:string');

        $this->assertStringNotContainsString('function kept', (new ResourceGenerator)->request($plain, [$plain]));
    }

    public function test_seeded_ratings_are_in_range_and_a_unique_one_knows_how_many_there_can_be(): void
    {
        $slot = Descriptor::fromSpec('Slot', 'stars:rating(10), small:rating');
        foreach (range(1, 300) as $n) {
            $row = Fake::row($slot, $n);
            $this->assertGreaterThanOrEqual(1, $row['stars']);
            $this->assertLessThanOrEqual(10, $row['stars']);
            $this->assertLessThanOrEqual(5, $row['small']);
        }

        $this->assertSame(10, Fake::capacity(Field::parse('stars:rating(10)!')));
        $this->assertSame(5, Fake::capacity(Field::parse('stars:rating!')));
    }
}
