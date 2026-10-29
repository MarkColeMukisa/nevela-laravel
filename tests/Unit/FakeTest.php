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
        $contact = Descriptor::fromSpec('Contact', 'name:string, email:email!, code:string!, slug:slug!, rank:int!, city:string?, phone:tel!, bio:text!, seenAt:datetime!, born:date!, tint:color!, title:string!');

        $seen = ['email' => [], 'code' => [], 'slug' => [], 'rank' => [], 'phone' => [], 'bio' => [], 'seen_at' => [], 'born' => [], 'tint' => [], 'title' => []];
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

    public function test_a_unique_field_with_few_possible_values_reports_its_capacity_and_fills_it_exactly(): void
    {
        $slot = Descriptor::fromSpec('Slot', 'label:string, position:enum(first|second|third)!, stars:rating!, open:boolean!');

        $this->assertNull(Fake::capacity($slot->fields['label']));
        $this->assertSame(3, Fake::capacity($slot->fields['position']));
        $this->assertSame(5, Fake::capacity($slot->fields['stars']));
        $this->assertSame(2, Fake::capacity($slot->fields['open']));

        // Any run of consecutive numbers, wherever it starts, covers each value once.
        $positions = array_map(fn ($n) => Fake::row($slot, $n)['position'], [5_000_001, 5_000_002, 5_000_003]);
        sort($positions);
        $this->assertSame(['first', 'second', 'third'], $positions);
        $stars = array_map(fn ($n) => Fake::row($slot, $n)['stars'], range(41, 45));
        sort($stars);
        $this->assertSame([1, 2, 3, 4, 5], $stars);
    }

    public function test_a_school_reads_like_one(): void
    {
        $course = Descriptor::fromSpec('Course', 'title:string, code:string!');
        $this->assertSame(['title' => 'Biology 101', 'code' => 'BIO-101'], Fake::row($course, 1));
        $this->assertSame(['title' => 'Algebra I', 'code' => 'ALG-102'], Fake::row($course, 2));

        $teacher = Descriptor::fromSpec('Teacher', 'name:string, email:email!, subject:string');
        $this->assertSame('Mathematics', Fake::row($teacher, 1)['subject']);

        // Born between 16 and 60 years ago, not last month.
        $student = Descriptor::fromSpec('Student', 'name:string, dateOfBirth:date');
        foreach (range(1, 50) as $number) {
            $age = (time() - strtotime(Fake::row($student, $number)['date_of_birth'])) / (365 * 86400);
            $this->assertTrue($age >= 15.9 && $age <= 60.1, "{$age} years");
        }
        // A message's subject is still a line of words.
        $this->assertNotContains(Fake::row(Descriptor::fromSpec('Message', 'email:email, subject:string'), 1)['subject'], ['Mathematics']);
    }
}
