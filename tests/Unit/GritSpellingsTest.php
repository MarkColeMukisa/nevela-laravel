<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What someone coming from Grit types, read as they mean it, or refused and not half read. */
final class GritSpellingsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function uniques(): iterable
    {
        yield 'after the type' => ['email:email:unique'];
        yield 'in capitals' => ['email:email:UNIQUE'];
        yield 'with spaces' => ['email : email : unique'];
        yield 'Nevela\'s own' => ['email:email!'];
    }

    #[DataProvider('uniques')]
    public function test_unique_after_a_type_makes_it_unique(string $written): void
    {
        $email = Field::parse($written);

        $this->assertTrue($email->unique, $written);
        $this->assertSame('email', $email->format);
        $this->assertTrue($email->required);
    }

    public function test_unique_goes_with_what_a_type_takes_and_with_optional(): void
    {
        $phone = Field::parse('phone:tel:UG:unique');
        $this->assertTrue($phone->unique);
        $this->assertSame('UG', $phone->country);

        $sku = Field::parse('sku:string:unique?');
        $this->assertTrue($sku->unique);
        $this->assertFalse($sku->required);

        $this->assertTrue(Field::parse('owner:belongs_to:Company:unique')->unique);
        $this->assertSame('Company', Field::parse('owner:belongs_to:Company:unique')->target);
    }

    public function test_select_and_radio_are_enums_whose_values_keep_their_labels(): void
    {
        $industry = Field::parse('industry:select[tech=Technology, retail, health, finance]');
        $this->assertSame('enum', $industry->kind);
        $this->assertSame(['tech', 'retail', 'health', 'finance'], $industry->options);
        $this->assertSame(['tech' => 'Technology'], $industry->optionLabels);

        $this->assertSame(['standard', 'express'], Field::parse('fulfillment:radio[standard, express]')->options);
    }

    public function test_a_label_in_quotes_is_the_label(): void
    {
        $terms = Field::parse('terms:select[net15="Net 15", net30="Net 30"]');

        $this->assertSame(['net15', 'net30'], $terms->options);
        $this->assertSame(['net15' => 'Net 15', 'net30' => 'Net 30'], $terms->optionLabels);
    }

    public function test_a_default_comes_after_the_brackets_whichever_they_are(): void
    {
        $this->assertSame('draft', Field::parse('status:select[draft, sent]=draft')->default);
        $this->assertSame('net30', Field::parse('terms:select[net15="Net 15", net30="Net 30"]=net30')->default);
        $this->assertSame('net30', Field::parse('terms:enum(net15=Net 15|net30=Net 30)=net30')->default);
    }

    public function test_a_whole_command_of_them_is_read_field_by_field(): void
    {
        $fields = Descriptor::fieldsOf('stage:select[lead, qualified, customer, lost], company:belongs_to:Company, email:email:unique, sent:toggle, notes:textarea');

        $this->assertSame(['stage', 'companyId', 'email', 'sent', 'notes'], array_keys($fields));
        $this->assertSame(['lead', 'qualified', 'customer', 'lost'], $fields['stage']->options);
        $this->assertTrue($fields['email']->unique);
        $this->assertSame('boolean', $fields['sent']->kind);
        $this->assertSame('text', $fields['notes']->kind);
    }

    public function test_toggle_and_checkbox_are_booleans(): void
    {
        $this->assertSame('boolean', Field::parse('sent:toggle')->kind);
        $this->assertSame('boolean', Field::parse('sent:checkbox')->kind);
    }

    /** @return iterable<string, array{string}> */
    public static function nonsense(): iterable
    {
        yield 'a word after an email' => ['email:email(work)'];
        yield 'a second colon on an email' => ['email:email:work'];
        yield 'a number after a date' => ['due:date(5)'];
        yield 'a length on a colour' => ['brand:color(7)'];
        yield 'a word after a string' => ['name:string(long)'];
        yield 'a currency on money' => ['price:money(USD)'];
        yield 'a number on money, which has no length to set' => ['price:money(2)'];
    }

    #[DataProvider('nonsense')]
    public function test_anything_else_after_a_type_is_refused_rather_than_dropped(string $written): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('means nothing to one');

        Field::parse($written);
    }

    /** @return iterable<string, array{string}> */
    public static function uniqueTexts(): iterable
    {
        yield 'text' => ['body:text!'];
        yield 'textarea' => ['body:textarea:unique'];
        yield 'rich text' => ['body:richtext!'];
        yield 'a text with a length' => ['body:text(100000)!'];
    }

    #[DataProvider('uniqueTexts')]
    public function test_a_text_cannot_be_unique_because_mysql_cannot_index_one(string $written): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('body:string!');

        Field::parse($written);
    }

    public function test_a_descriptor_that_already_says_so_is_still_read(): void
    {
        $this->assertTrue(Field::fromArray('body', ['kind' => 'text', 'unique' => true])->unique);
    }

    public function test_what_a_type_does_take_still_works(): void
    {
        $this->assertSame(500, Field::parse('excerpt:string(500)')->maxLength);
        $this->assertSame(100000, Field::parse('body:text(100000)')->maxLength);
        $this->assertSame(20, Field::parse('phone:tel(20)')->maxLength);
        $this->assertSame('UG', Field::parse('phone:tel(UG)')->country);
        $this->assertSame('title', Field::parse('slug:slug(title)')->from);
        $this->assertSame('productId.price', Field::parse('price:money(product.price)')->from);
        $this->assertSame('INV', Field::parse('number:string:auto:INV')->auto);
    }
}
