<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Action;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Fake;
use Nevela\Laravel\Support\Field;
use Nevela\Laravel\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A phone field held to one country, phone:tel(UG): its numbers are that country's, kept as +256772123456. */
final class PhoneTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function written(): array
    {
        return [
            'at home' => ['0772 123456', '+256772123456'],
            'without the 0' => ['772123456', '+256772123456'],
            'with the code' => ['256772123456', '+256772123456'],
            'international' => ['+256 772 123 456', '+256772123456'],
            'dialled from abroad' => ['00256772123456', '+256772123456'],
            'with dashes and brackets' => ['(0772) 123-456', '+256772123456'],
            'a landline' => ['0414 123456', '+256414123456'],
            'already kept' => ['+256772123456', '+256772123456'],
            'Kenyan' => ['+254712345678', null],
            'too short' => ['0772 12345', null],
            'too long' => ['0772 1234567', null],
            'two 0s' => ['00772123456', null],
            'words' => ['call me', null],
            'an extension' => ['0772 123456 ext 2', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('written')]
    public function test_a_number_is_read_the_ways_people_write_it_there(string $value, ?string $kept): void
    {
        $this->assertSame($kept, Phone::normalise($value, 'UG'));
    }

    public function test_the_field_says_its_country_however_it_is_written(): void
    {
        foreach (['phone:tel(UG)', 'phone:tel(ug)', 'phone:tel[UG]', 'phone:tel:UG'] as $written) {
            $this->assertSame('UG', Field::parse($written)->country, $written);
        }
        $this->assertNull(Field::parse('phone:tel')->country);
        // A number in the brackets is still a length.
        $this->assertSame(20, Field::parse('phone:tel(20)')->maxLength);
        $this->assertNull(Field::parse('phone:tel(20)')->country);

        $field = Field::parse('phone:tel(UG)?');
        $this->assertSame(['kind' => 'string', 'format' => 'tel', 'country' => 'UG', 'required' => false], $field->toArray());
        $this->assertEquals($field, Field::fromArray('phone', $field->toArray()));
    }

    public function test_a_country_is_one_nevela_knows_and_only_on_a_phone(): void
    {
        try {
            Field::parse('phone:tel(ZZ)');
            $this->fail('ZZ was taken');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Use one of: UG, KE, TZ, RW, or tel alone', $e->getMessage());
        }
        $this->expectExceptionMessage("can't have a country");
        Field::fromArray('email', ['kind' => 'string', 'format' => 'email', 'country' => 'UG']);
    }

    public function test_what_is_said_of_a_number_that_isn_t_one(): void
    {
        $this->assertSame('Enter a Ugandan number, such as 0772 123456 or +256 772 123456.', Phone::message('UG'));
        $this->assertSame('Enter a Kenyan number, such as 0712 345678 or +254 712 345678.', Phone::message('KE'));
    }

    public function test_seeded_numbers_are_the_country_s_and_kept_as_a_typed_one_would_be(): void
    {
        $client = Descriptor::fromSpec('Client', 'name:string, phone:tel(UG)!, mobile:tel(KE)');
        $seen = [];
        foreach (range(1, 300) as $number) {
            $row = Fake::row($client, $number);
            $this->assertSame($row['phone'], Phone::normalise($row['phone'], 'UG'));
            $this->assertSame($row['mobile'], Phone::normalise($row['mobile'], 'KE'));
            $this->assertStringStartsWith('+25677', $row['phone']);
            $this->assertArrayNotHasKey($row['phone'], $seen);
            $seen[$row['phone']] = true;
        }
    }

    public function test_what_is_generated_checks_the_number_and_keeps_it_in_its_one_form(): void
    {
        $client = Descriptor::fromSpec('Client', 'name:string, phone:tel(UG), fax:tel?');
        $generator = new ResourceGenerator;

        $request = $generator->request($client);
        $this->assertStringContainsString("'phone' => ['required', 'string', 'max:32', new \\Nevela\\Laravel\\Rules\\Phone('UG')],", $request);
        $this->assertStringContainsString("'fax' => ['nullable', 'string', 'max:32'],", $request);
        $this->assertStringContainsString("return ['phone' => 'UG'];", $request);
        $this->assertStringContainsString('phone: field.tel({ ...({ country: "UG" } as {}) }),', $generator->typescript($client));
        $this->assertStringContainsString('fax: field.tel({ required: false }),', $generator->typescript($client));

        // A resource without one has the request it always had.
        $this->assertStringNotContainsString('function phones()', $generator->request(Descriptor::fromSpec('Note', 'title:string, phone:tel')));
    }

    public function test_an_action_asking_for_a_number_checks_it_the_same_way(): void
    {
        $action = Action::fromArray('call', ['label' => 'Call back', 'fields' => ['number' => ['kind' => 'string', 'format' => 'tel', 'country' => 'UG']]]);

        $this->assertInstanceOf(\Nevela\Laravel\Rules\Phone::class, $action->rules()['number'][3]);
        $this->assertSame('UG', $action->toArray()['fields']['number']['country']);
    }
}
