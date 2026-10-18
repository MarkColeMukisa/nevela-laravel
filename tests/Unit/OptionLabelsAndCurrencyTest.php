<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What an enum's values are called, and the currency money is in: in the descriptor, and in what is generated from it. */
final class OptionLabelsAndCurrencyTest extends TestCase
{
    private const TIMELINE = [
        'kind' => 'enum',
        'options' => ['asap', 'month', 'quarter', 'flexible'],
        'optionLabels' => ['asap' => 'As soon as possible', 'month' => 'Within a month', 'quarter' => 'In 1 to 3 months'],
    ];

    private function request(array $fields = [], array $actions = []): Descriptor
    {
        return Descriptor::fromArray(array_filter([
            'name' => 'ProjectRequest',
            'fields' => $fields + [
                'timeline' => self::TIMELINE,
                'quoteAmount' => ['kind' => 'float', 'format' => 'money', 'currency' => 'UGX', 'required' => false],
                'budget' => ['kind' => 'float', 'format' => 'money', 'required' => false],
            ],
            'actions' => $actions,
        ]));
    }

    public function test_labels_and_currency_are_read_and_written_back_as_they_were(): void
    {
        $request = $this->request();

        $this->assertSame(self::TIMELINE['optionLabels'], $request->fields['timeline']->optionLabels);
        $this->assertSame('UGX', $request->fields['quoteAmount']->currency);
        $this->assertNull($request->fields['budget']->currency);
        $this->assertSame(self::TIMELINE, $request->toArray()['fields']['timeline']);
        $this->assertEquals($request, Descriptor::fromJson($request->toJson()));
    }

    public function test_a_value_is_called_by_its_label_or_made_readable_as_the_dashboard_does(): void
    {
        $timeline = $this->request()->fields['timeline'];

        $this->assertSame('In 1 to 3 months', $timeline->optionLabel('quarter'));
        // No label: as Flare's optionLabel() has it.
        $this->assertSame('Flexible', $timeline->optionLabel('flexible'));
        $this->assertSame('On hold', Field::fromArray('status', ['kind' => 'enum', 'options' => ['on_hold']])->optionLabel('on_hold'));
        $this->assertSame('Not started', Field::fromArray('status', ['kind' => 'enum', 'options' => ['not-started']])->optionLabel('not-started'));
        $this->assertSame('', $timeline->optionLabel(null));
    }

    public function test_the_shorthand_names_values_and_keeps_its_default(): void
    {
        $field = Field::parse('timeline:enum(asap=As soon as possible|month=Within a month, or so|flexible)=flexible');

        $this->assertSame(['asap', 'month', 'flexible'], $field->options);
        $this->assertSame(['asap' => 'As soon as possible', 'month' => 'Within a month, or so'], $field->optionLabels);
        $this->assertSame('flexible', $field->default);

        // And as one of several, a comma inside the brackets being part of a label.
        $request = Descriptor::fromSpec('ProjectRequest', 'title:string, timeline:enum(asap=As soon as possible|month=Within a month, or so), quote:money?');
        $this->assertSame(['title', 'timeline', 'quote'], array_keys($request->fields));
        $this->assertSame('Within a month, or so', $request->fields['timeline']->optionLabel('month'));
        // The old shorthand is as it was.
        $this->assertSame([], Field::parse('status:enum(draft|live)=draft')->optionLabels);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function mistakes(): array
    {
        return [
            'a label for a value it lacks' => [['kind' => 'enum', 'options' => ['a', 'b'], 'optionLabels' => ['c' => 'See']], 'a label for "c", which is not one of its values: a, b'],
            'labels on something that is not an enum' => [['kind' => 'string', 'optionLabels' => ['a' => 'A']], "can't have option labels"],
            'an empty label' => [['kind' => 'enum', 'options' => ['a'], 'optionLabels' => ['a' => ' ']], 'has to be some words'],
            'a currency that is not one' => [['kind' => 'float', 'format' => 'money', 'currency' => 'shillings'], '"shillings" on "field" is not a currency'],
            'a currency in small letters' => [['kind' => 'float', 'format' => 'money', 'currency' => 'ugx'], 'is not a currency'],
            'a currency on a number that is not money' => [['kind' => 'float', 'currency' => 'UGX'], "can't have a currency"],
        ];
    }

    /** @param array<string, mixed> $definition */
    #[DataProvider('mistakes')]
    public function test_a_mistake_is_refused_and_says_which(array $definition, string $says): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($says);

        Field::fromArray('field', $definition);
    }

    public function test_the_dashboard_is_given_the_labels_and_the_currency(): void
    {
        $ts = (new ResourceGenerator)->typescript($this->request());

        $this->assertStringContainsString('timeline: field.enum(["asap","month","quarter","flexible"], { optionLabels: {"asap":"As soon as possible","month":"Within a month","quarter":"In 1 to 3 months"} }),', $ts);
        $this->assertStringContainsString('quoteAmount: field.float({ required: false, format: "money", currency: "UGX" }),', $ts);
        // With no currency of its own and none for the app, money is as it was.
        $this->assertStringContainsString('budget: field.float({ required: false, format: "money" }),', $ts);
    }

    public function test_the_apps_currency_is_the_one_money_is_in_unless_a_field_says(): void
    {
        $ts = (new ResourceGenerator('USD'))->typescript($this->request());

        $this->assertStringContainsString('budget: field.float({ required: false, format: "money", currency: "USD" }),', $ts);
        $this->assertStringContainsString('quoteAmount: field.float({ required: false, format: "money", currency: "UGX" }),', $ts);

        $this->expectExceptionMessage('NEVELA_CURRENCY is "dollars", which is not a currency');
        new ResourceGenerator('dollars');
    }

    public function test_an_actions_sheet_gets_the_same(): void
    {
        $request = $this->request(actions: ['sendQuote' => [
            'label' => 'Send quote',
            'fields' => [
                'amount' => ['kind' => 'float', 'format' => 'money'],
                'within' => ['kind' => 'enum', 'options' => ['week', 'month'], 'optionLabels' => ['week' => 'Within a week']],
            ],
        ]]);

        $registry = (new ResourceGenerator('UGX'))->registry([$request])->contents;
        $extras = json_decode(substr($registry, $at = strpos($registry, '= {') + 2, strrpos($registry, '};') - $at + 1), true);
        $fields = $extras['ProjectRequest']['actions']['sendQuote']['fields'];

        $this->assertSame('UGX', $fields['amount']['currency']);
        $this->assertSame(['week' => 'Within a week'], $fields['within']['optionLabels']);
    }

    public function test_a_model_with_an_enum_can_say_what_its_value_is_called(): void
    {
        $generator = new ResourceGenerator;

        $this->assertStringContainsString('use \\Nevela\\Laravel\\Concerns\\OptionLabels;', $generator->model($this->request()));
        $this->assertStringNotContainsString('OptionLabels', $generator->model(Descriptor::fromSpec('Note', 'title:string')));
    }

    public function test_with_neither_nothing_generated_changes(): void
    {
        $plain = Descriptor::fromSpec('Product', 'name:string, price:money, kind:enum(stock|digital)');
        $ts = (new ResourceGenerator)->typescript($plain);

        $this->assertStringNotContainsString('optionLabels', $ts);
        $this->assertStringNotContainsString('currency', $ts);
        $this->assertStringContainsString('price: field.float({ format: "money" }),', $ts);
    }
}
