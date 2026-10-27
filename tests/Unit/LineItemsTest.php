<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Console\MakeResourceCommand;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Generator\Writer;
use Nevela\Laravel\Support\Descriptor;
use PHPUnit\Framework\TestCase;

/** A resource with lines (an order's items): what is generated for it, and how --items reads. */
final class LineItemsTest extends TestCase
{
    /** @return array{Descriptor, Descriptor, Descriptor} The order, its lines and the product they point at */
    private function shop(): array
    {
        $product = Descriptor::fromSpec('Product', 'name:string, price:money');
        $order = Descriptor::fromSpec('Order', 'number:string!, status:enum(pending|paid)');
        $items = MakeResourceCommand::lines($order, 'OrderItem: product:belongsTo(Product), quantity:int, price:money');

        return [Descriptor::fromArray(['items' => $items->name] + $order->toArray()), $items, $product];
    }

    public function test_items_reads_the_name_then_the_fields_and_points_the_lines_back(): void
    {
        $order = Descriptor::fromSpec('Order', 'number:string');
        $lines = MakeResourceCommand::lines($order, 'OrderItem: product:belongsTo(Product), quantity:int');
        $this->assertSame('OrderItem', $lines->name);
        $this->assertSame(['productId', 'quantity', 'orderId'], array_keys($lines->fields));
        $this->assertSame('Order', $lines->fields['orderId']->target);
        $this->assertTrue($lines->fields['orderId']->required);

        // Grit's way of writing it reads the same, and a field of their own pointing back is kept.
        $grit = MakeResourceCommand::lines(Descriptor::fromSpec('Invoice', 'number:string'), 'InvoiceItem:description:string,qty:int,invoice:belongs_to:Invoice');
        $this->assertSame(['description', 'qty', 'invoiceId'], array_keys($grit->fields));

        $this->expectException(InvalidArgumentException::class);
        MakeResourceCommand::lines($order, 'OrderItem');
    }

    public function test_the_descriptor_keeps_its_items_and_refuses_itself(): void
    {
        [$order] = $this->shop();
        $this->assertSame('OrderItem', Descriptor::fromJson($order->toJson())->items);

        $this->expectException(InvalidArgumentException::class);
        Descriptor::fromArray(['items' => 'Order'] + $order->toArray());
    }

    public function test_an_order_saves_its_lines_with_it_and_answers_with_them(): void
    {
        [$order, $items, $product] = $this->shop();
        $all = [$order, $items, $product];
        $generator = new ResourceGenerator;

        $lines = $generator->lineItems($order, $all);
        $this->assertSame('OrderItem', $lines['resource']->name);
        $this->assertSame('orderItems', $lines['name']);
        $this->assertNull($generator->lineItems($product, $all));

        $controller = $generator->controller($order, $all);
        $this->assertStringContainsString('$request->saveItems($order);', $controller);
        $this->assertStringContainsString('DB::transaction(function () use ($request, $order) {', $controller);
        $this->assertStringContainsString("return new OrderResource(\$order->load('orderItems'));", $controller);
        // Its lines go with it, so deleting it isn't refused for having them.
        $this->assertStringNotContainsString('belong to this order', $controller);

        $this->assertStringContainsString("'model' => \\App\\Models\\OrderItem::class, 'field' => 'orderId', 'column' => 'order_id'", $generator->request($order, $all));
        $this->assertStringContainsString("'items' => \$this->whenLoaded('orderItems', fn () => OrderItemResource::collection(\$this->orderItems)->resolve(\$request)),", $generator->resource($order, $all));
        $this->assertStringContainsString("return \$this->hasMany(OrderItem::class, 'order_id')->orderBy('id');", $generator->model($order, $all));
        $this->assertStringContainsString('"items": {'."\n".'            "resource": "OrderItem",'."\n".'            "field": "orderId"', $generator->registry($all)->contents);

        // A product with lines pointing at it is an ordinary parent: those are not its lines.
        $this->assertStringContainsString('belong to this product', $generator->controller($product, $all));
        $this->assertStringNotContainsString('saveItems', $generator->controller($items, $all));
    }

    public function test_what_is_generated_for_lines_is_valid_php(): void
    {
        [$order, $items, $product] = $this->shop();
        $all = [$order, $items, $product];
        $dir = sys_get_temp_dir().'/nevela-lines-'.bin2hex(random_bytes(4));
        $generator = new ResourceGenerator;
        $files = [...$generator->forResource($order, '2026_10_27_000000', $all), ...$generator->forResource($items, '2026_10_27_000001', $all)];
        $report = (new Writer(['api' => "{$dir}/api", 'web' => "{$dir}/web"]))->write($files);

        foreach (array_column($report, 'path') as $path) {
            if (str_ends_with($path, '.php')) {
                exec('php -l '.escapeshellarg($path).' 2>&1', $out, $code);
                $this->assertSame(0, $code, implode("\n", $out));
            }
        }
    }
}
