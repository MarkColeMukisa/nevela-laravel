<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use PHPUnit\Framework\TestCase;

final class BooleansLeftOutTest extends TestCase
{
    public function test_only_a_required_yes_or_no_has_a_value_without_being_given_one(): void
    {
        $this->assertFalse(Field::parse('sent:boolean')->whenLeftOut());
        $this->assertTrue(Field::parse('active:boolean=true')->whenLeftOut());
        $this->assertNull(Field::parse('sent:boolean?')->whenLeftOut());
        $this->assertNull(Field::parse('name:string')->whenLeftOut());
        $this->assertSame('net30', Field::parse('terms:enum(net15|net30)=net30')->whenLeftOut());
    }

    public function test_one_that_only_sometimes_applies_is_left_alone(): void
    {
        $this->assertNull(Field::parse('sent:boolean if kind == once')->whenLeftOut());
    }

    public function test_left_out_is_written_into_the_rules_and_the_form_and_not_the_descriptor(): void
    {
        $invoice = Descriptor::fromSpec('Invoice', 'number:string, sent:boolean');
        $generator = new ResourceGenerator;

        $request = $generator->request($invoice, [$invoice]);
        $this->assertStringContainsString("'sent' => ['sometimes', 'required', 'boolean']", $request);
        $this->assertStringContainsString("return ['sent' => false];", $request);
        $this->assertStringContainsString('sent: field.boolean({ default: false })', $generator->typescript($invoice, [$invoice]));
        $this->assertArrayNotHasKey('default', $invoice->toArray()['fields']['sent']);
    }

    public function test_its_column_is_as_it_was(): void
    {
        $this->assertStringContainsString("\$table->boolean('sent')->default(false);", (new ResourceGenerator)->migration(Descriptor::fromSpec('Invoice', 'sent:boolean')));
    }
}
