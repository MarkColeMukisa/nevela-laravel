<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Action;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Fake;
use Nevela\Laravel\Support\Field;
use PHPUnit\Framework\TestCase;

/** A field that applies for some values of another only: cycle:enum(monthly|annually) if kind == recurring. */
final class ConditionalFieldsTest extends TestCase
{
    private const PROJECT = 'name:string, kind:enum(one_off|recurring|retainer), cycle:enum(monthly|annually) if kind == recurring, rate:money? if kind == recurring|retainer, billed:boolean, terms:text if billed == true';

    private function project(): Descriptor
    {
        return Descriptor::fromSpec('Project', self::PROJECT);
    }

    public function test_grit_s_words_say_what_it_applies_for(): void
    {
        $fields = $this->project()->fields;

        $this->assertSame(['kind' => ['recurring']], $fields['cycle']->when);
        $this->assertSame(['kind' => ['recurring', 'retainer']], $fields['rate']->when);
        $this->assertSame(['billed' => [true]], $fields['terms']->when);
        $this->assertTrue($fields['cycle']->required);
        $this->assertFalse($fields['cycle']->alwaysRequired());
        $this->assertFalse($fields['rate']->required);
        $this->assertTrue($fields['name']->alwaysRequired());
        // "=" is read as "==", and the other field's name as camelCase.
        $this->assertSame(['payKind' => ['card']], Field::parse('card:string if pay_kind = card')->when);
    }

    public function test_it_is_written_to_the_descriptor_and_read_back_as_it_was(): void
    {
        $project = $this->project();

        $this->assertSame(['kind' => 'enum', 'options' => ['monthly', 'annually'], 'when' => ['kind' => 'recurring']], $project->toArray()['fields']['cycle']);
        $this->assertSame(['kind' => ['recurring', 'retainer']], $project->toArray()['fields']['rate']['when']);
        $this->assertEquals($project, Descriptor::fromJson($project->toJson()));
    }

    public function test_what_it_depends_on_has_to_be_a_field_that_can_have_those_values(): void
    {
        $refused = [
            'no such field' => ['cycle:string if plan == recurring', '"plan", which has to be an enum or a yes/no field'],
            'a string' => ['kind:string, cycle:string if kind == recurring', 'has to be an enum or a yes/no field'],
            'not one of its values' => ['kind:enum(one_off|recurring), cycle:string if kind == weekly', 'applies when "kind" is weekly, which it can\'t be. Use one of: one_off, recurring.'],
            'yes or no' => ['billed:boolean, terms:text if billed == yes', 'Use true or false.'],
            'a chain' => ['kind:enum(a|b), sub:enum(x|y) if kind == a, note:text if sub == x', 'which only sometimes applies itself'],
            'itself' => ['kind:enum(a|b) if kind == a', 'one other field of the same resource'],
            'a relation' => ['kind:enum(a|b), client:belongsTo(Client) if kind == a', 'Make it optional instead: clientId:belongsTo(Client)?'],
            'numbered' => ['kind:enum(a|b), number:auto(INV) if kind == a', 'it is numbered'],
            'with a default' => ['kind:enum(a|b), cycle:enum(m|y)=m if kind == a', 'it is given a default'],
        ];
        foreach ($refused as $case => [$spec, $message]) {
            try {
                Descriptor::fromSpec('Project', $spec);
                $this->fail("{$case} was taken");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $case);
            }
        }
    }

    public function test_an_action_s_sheet_asks_for_its_fields_always(): void
    {
        $this->expectExceptionMessage('only sometimes');
        Action::fromArray('bill', ['fields' => ['kind' => ['kind' => 'enum', 'options' => ['a', 'b']], 'note' => ['kind' => 'text', 'when' => ['kind' => 'a']]]]);
    }

    public function test_what_is_generated_empties_it_where_it_doesn_t_apply_and_needs_it_where_it_does(): void
    {
        $generator = new ResourceGenerator;
        $project = $this->project();

        $migration = $generator->migration($project);
        $this->assertStringContainsString("\$table->string('cycle')->nullable()->index();", $migration);
        $this->assertStringContainsString("\$table->string('name');", $migration);

        $request = $generator->request($project);
        $this->assertStringContainsString("'cycle' => ['nullable', Rule::in(['monthly', 'annually'])],", $request);
        $this->assertStringContainsString("'cycle' => ['on' => 'kind', 'values' => ['recurring'], 'required' => true, 'message' => 'Needed when the kind is Recurring.']", $request);
        $this->assertStringContainsString("'rate' => ['on' => 'kind', 'values' => ['recurring', 'retainer'], 'required' => false, 'message' => 'Needed when the kind is Recurring or Retainer.']", $request);
        $this->assertStringContainsString("'terms' => ['on' => 'billed', 'values' => [true], 'required' => true, 'message' => 'Needed when billed is on.']", $request);

        $ts = $generator->typescript($project);
        $this->assertStringContainsString('cycle: field.enum(["monthly","annually"], { required: false, ...({ when: {"field":"kind","values":["recurring"],"required":true} } as {}) }),', $ts);

        // A resource without one has the request it always had.
        $this->assertStringNotContainsString('function conditions()', $generator->request(Descriptor::fromSpec('Note', 'title:string')));
    }

    public function test_seeded_records_have_it_only_where_it_applies(): void
    {
        $project = $this->project();
        $kinds = [];
        foreach (range(1, 200) as $number) {
            $row = Fake::row($project, $number);
            $kinds[$row['kind']] = true;
            if ($row['kind'] === 'recurring') {
                $this->assertContains($row['cycle'], ['monthly', 'annually']);
            } else {
                $this->assertNull($row['cycle']);
            }
            if ($row['kind'] === 'one_off') {
                $this->assertNull($row['rate']);
            }
            if (! $row['billed']) {
                $this->assertNull($row['terms']);
            }
        }
        $this->assertCount(3, $kinds);
    }

    public function test_a_value_is_one_it_applies_for_however_the_database_gives_it(): void
    {
        $this->assertTrue(Field::holds('recurring', ['recurring']));
        $this->assertFalse(Field::holds('one_off', ['recurring']));
        $this->assertFalse(Field::holds(null, ['recurring']));
        foreach ([true, 1, '1', 'true'] as $yes) {
            $this->assertTrue(Field::holds($yes, [true]), var_export($yes, true));
            $this->assertFalse(Field::holds($yes, [false]), var_export($yes, true));
        }
        foreach ([false, 0, '0'] as $no) {
            $this->assertTrue(Field::holds($no, [false]), var_export($no, true));
        }
        $this->assertFalse(Field::holds(null, [false]));
    }
}
