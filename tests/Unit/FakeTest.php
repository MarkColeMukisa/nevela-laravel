<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Fake;
use PHPUnit\Framework\TestCase;

final class FakeTest extends TestCase
{
    public function test_rows_follow_the_descriptor(): void
    {
        $product = Descriptor::fromSpec('Product', 'name:string, sku:string!, price:money, stock:int?, discount:percent, active:boolean, kind:enum(stock|digital), launchOn:date?, notes:text?, website:url?, tint:color');

        for ($n = 1; $n <= 200; $n++) {
            $row = Fake::row($product, $n);

            $this->assertSame(['name', 'sku', 'price', 'stock', 'discount', 'active', 'kind', 'launch_on', 'notes', 'website', 'tint'], array_keys($row));
            $this->assertNotSame('', $row['name']);
            $this->assertLessThanOrEqual(255, strlen($row['name']));
            $this->assertIsFloat($row['price']);
            $this->assertContains($row['kind'], ['stock', 'digital']);
            $this->assertIsBool($row['active']);
            $this->assertTrue($row['discount'] >= 0 && $row['discount'] <= 100);
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $row['tint']);
            if ($row['launch_on'] !== null) {
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['launch_on']);
            }
            if ($row['website'] !== null) {
                $this->assertNotFalse(filter_var($row['website'], FILTER_VALIDATE_URL));
            }
        }
    }

    public function test_unique_fields_never_repeat_and_required_fields_are_never_empty(): void
    {
        $contact = Descriptor::fromSpec('Contact', 'name:string, email:email!, code:string!, slug:slug!, rank:int!, city:string?');

        $seen = ['email' => [], 'code' => [], 'slug' => [], 'rank' => []];
        for ($n = 1; $n <= 2000; $n++) {
            $row = Fake::row($contact, $n);
            $this->assertNotNull($row['name']);
            $this->assertNotFalse(filter_var($row['email'], FILTER_VALIDATE_EMAIL));
            foreach (array_keys($seen) as $column) {
                $this->assertArrayNotHasKey((string) $row[$column], $seen[$column], "{$column} repeated at row {$n}");
                $seen[$column][(string) $row[$column]] = true;
            }
        }
    }
}
