<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Fake;
use Nevela\Laravel\Support\Field;
use Nevela\Laravel\Support\Numbering;
use PHPUnit\Framework\TestCase;

final class NumbersAndCopiesTest extends TestCase
{
    public function test_a_number_is_written_as_nevela_and_grit_write_it(): void
    {
        $field = Field::parse('number:auto(INV)');

        $this->assertSame(['string', 'INV', true, true], [$field->kind, $field->auto, $field->unique, $field->required]);
        $this->assertEquals($field, Field::parse('number:string:auto:INV'));
        $this->assertEquals($field, Field::parse('number:auto:INV'));
        $this->assertEquals($field, Field::fromArray('number', ['kind' => 'string', 'auto' => 'INV']));
        $this->assertSame(['kind' => 'string', 'auto' => 'INV', 'unique' => true], $field->toArray());
        $this->assertSame('INV-0001', Numbering::format('INV', 1));
        $this->assertSame('INV-10000', Numbering::format('INV', 10000));
    }

    public function test_a_copy_is_written_as_the_relation_and_its_field(): void
    {
        $field = Field::parse('price:money(product.price)');

        $this->assertSame(['float', 'money', 'productId.price'], [$field->kind, $field->format, $field->from]);
        $this->assertEquals($field, Field::parse('price:money:product.price'));
        $this->assertSame('productId.unitPrice', Field::parse('price:money(productId.unit_price)')->from);
        $this->assertEquals($field, Field::fromArray('price', $field->toArray()));
    }

    public function test_what_makes_no_sense_is_refused(): void
    {
        foreach ([
            'number:auto' => 'prefix of your choosing',
            'number:auto(1NV)' => 'starting with a letter',
            'count:int:auto:INV' => 'Invalid field',
        ] as $token => $message) {
            try {
                Field::parse($token);
                $this->fail("{$token} was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $token);
            }
        }
        foreach ([
            ['email', ['kind' => 'string', 'format' => 'email', 'auto' => 'INV'], "can't be numbered"],
            ['number', ['kind' => 'string', 'auto' => 'INV', 'unique' => false], 'has to be unique'],
            ['price', ['kind' => 'float', 'from' => 'product.price'], 'relation.field'],
            ['price', ['kind' => 'float', 'unique' => true, 'from' => 'productId.price'], 'not unique'],
        ] as [$name, $data, $message]) {
            try {
                Field::fromArray($name, $data);
                $this->fail("{$name} was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $name);
            }
        }

        $this->expectExceptionMessage('has to be a relation of OrderItem');
        Descriptor::fromSpec('OrderItem', 'quantity:int, price:money(product.price)');
    }

    public function test_the_model_numbers_and_copies_and_the_request_leaves_the_copy_out(): void
    {
        $product = Descriptor::fromSpec('Product', 'name:string, price:money');
        $order = Descriptor::fromSpec('Order', 'number:auto(ORD), customer:string');
        $item = Descriptor::fromSpec('OrderItem', 'product:belongsTo(Product), quantity:int, price:money(product.price)');
        $all = [$product, $order, $item];
        $generator = new ResourceGenerator;

        $orderModel = $generator->model($order, $all);
        $this->assertStringContainsString('use \Nevela\Laravel\Concerns\Numbered;', $orderModel);
        $this->assertStringContainsString("return ['number' => 'ORD'];", $orderModel);
        $this->assertStringContainsString('ORD-0001, ORD-0002', $orderModel);
        $this->assertLessThan(strpos($orderModel, 'nevela:generated:end'), strpos($orderModel, 'function numbered'));
        $this->assertStringContainsString("'number' => ['nullable', 'string'", $generator->request($order, $all));
        $this->assertStringContainsString('number: field.string({ required: false, unique: true, placeholder: "Left empty: the next ORD- number", ...({ auto: "ORD" } as {}) }),', $generator->typescript($order, $all));
        $this->assertStringContainsString("\$table->string('number')->unique();", $generator->migration($order, $all));

        $itemModel = $generator->model($item, $all);
        $this->assertStringContainsString('use \Nevela\Laravel\Concerns\Copies;', $itemModel);
        $this->assertStringContainsString("return ['price' => ['product_id', Product::class, 'price']];", $itemModel);
        $request = $generator->request($item, $all);
        $this->assertStringContainsString("protected function copied(): array\n    {\n        return ['price'];", $request);
        $this->assertStringContainsString("'price' => ['nullable', 'numeric'", $request);
        // A copy is not a slug: nothing is made for it from text.
        $this->assertStringNotContainsString('function made()', $request);
        $this->assertStringContainsString('price: field.float({ required: false, format: "money", ...({ from: "productId.price" } as {}) }),', $generator->typescript($item, $all));
    }

    public function test_seeded_numbers_follow_the_row(): void
    {
        $order = Descriptor::fromSpec('Order', 'number:auto(INV), customer:string');

        $this->assertSame('INV-0007', Fake::row($order, 7)['number']);
        $this->assertSame('INV-12345', Fake::row($order, 12345)['number']);
    }

    public function test_a_public_form_leaves_out_what_laravel_fills(): void
    {
        // A student who signs up is given the next number; nobody types it.
        $student = Descriptor::fromArray(['public' => ['create' => ['fields' => ['name']]]] + Descriptor::fromSpec('Student', 'name:string, number:auto(STU)')->toArray());
        $this->assertSame(['name'], $student->public->create);

        $this->expectExceptionMessage('"code" is required and a public create can\'t send it');
        Descriptor::fromArray(['public' => ['create' => ['fields' => ['name']]]] + Descriptor::fromSpec('Student', 'name:string, code:string')->toArray());
    }
}
