<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use PHPUnit\Framework\TestCase;

final class AddFieldsTest extends TestCase
{
    private function migration(string $spec): string
    {
        $fields = Descriptor::fieldsOf($spec);
        $invoice = Descriptor::fromSpec('Invoice', 'number:string, kind:enum(once|repeat)=once, '.$spec);

        return (new ResourceGenerator)->addFieldsMigration($invoice, array_values($fields), [$invoice, Descriptor::fromSpec('Customer', 'name:string')]);
    }

    public function test_a_required_field_s_column_is_empty_for_the_records_already_there(): void
    {
        $migration = $this->migration('dueDate:date');

        $this->assertStringContainsString("Schema::table('invoices'", $migration);
        $this->assertStringContainsString("\$table->date('due_date')->nullable();", $migration);
        $this->assertStringContainsString("\$table->dropColumn('due_date');", $migration);

        // Where it is made with its table, there are no such records.
        $this->assertStringContainsString("\$table->date('due_date');", (new ResourceGenerator)->migration(Descriptor::fromSpec('Invoice', 'dueDate:date')));
    }

    public function test_a_default_gives_them_a_value_and_so_does_a_boolean(): void
    {
        $this->assertStringContainsString("\$table->string('terms')->default('net30')->index();", $this->migration('terms:enum(net15|net30)=net30'));
        $this->assertStringContainsString("\$table->boolean('sent')->default(false);", $this->migration('sent:boolean'));
    }

    public function test_a_text_field_s_default_is_the_request_s_to_supply(): void
    {
        $this->assertStringContainsString("\$table->text('notes')->nullable();", $this->migration('notes:text="None"'));
    }

    public function test_a_required_relation_starts_empty_and_still_holds_on_to_its_parent(): void
    {
        $migration = $this->migration('customer:belongsTo(Customer)');

        $this->assertStringContainsString("\$table->foreignUuid('customer_id')->nullable()->index()->constrained('customers');", $migration);
    }

    public function test_an_optional_relation_lets_go_of_its_parent(): void
    {
        $this->assertStringContainsString("->constrained('customers')->nullOnDelete();", $this->migration('customer:belongsTo(Customer)?'));
    }

    public function test_an_index_is_taken_away_before_its_column(): void
    {
        $down = substr($this->migration('customer:belongsTo(Customer)?, code:string!, terms:enum(net15|net30)?'), 0);
        $down = substr($down, (int) strpos($down, 'function down'));

        $this->assertLessThan(strpos($down, "dropIndex(['customer_id'])"), strpos($down, "dropForeign(['customer_id'])"));
        $this->assertLessThan(strpos($down, "dropColumn('customer_id')"), strpos($down, "dropIndex(['customer_id'])"));
        $this->assertLessThan(strpos($down, "dropColumn('code')"), strpos($down, "dropUnique(['code'])"));
        $this->assertLessThan(strpos($down, "dropColumn('terms')"), strpos($down, "dropIndex(['terms'])"));
    }

    public function test_the_migration_is_named_after_what_it_adds_while_that_fits(): void
    {
        $file = function (string $spec) {
            $fields = array_values(Descriptor::fieldsOf($spec));

            return (new ResourceGenerator)->addFieldsMigrationFile(Descriptor::fromSpec('Invoice', 'number:string, '.$spec), $fields, '2026_11_01_000000')->path;
        };

        $this->assertSame('database/migrations/2026_11_01_000000_add_due_date_to_invoices_table.php', $file('dueDate:date'));
        $this->assertSame('database/migrations/2026_11_01_000000_add_a_and_b_to_invoices_table.php', $file('a:string, b:string'));
        $this->assertSame('database/migrations/2026_11_01_000000_add_4_fields_to_invoices_table.php', $file('a:string, b:string, c:string, d:string'));
    }

    public function test_fields_are_read_as_they_are_for_a_new_resource(): void
    {
        $fields = Descriptor::fieldsOf('dueDate:date, terms:enum(net15|net30)?, cycle:enum(monthly|yearly) if kind == repeat');

        $this->assertSame(['dueDate', 'terms', 'cycle'], array_keys($fields));
        $this->assertContainsOnlyInstancesOf(Field::class, $fields);
        $this->assertSame(['kind' => ['repeat']], $fields['cycle']->when);

        $this->expectException(InvalidArgumentException::class);
        Descriptor::fieldsOf('a:string, a:int');
    }
}
