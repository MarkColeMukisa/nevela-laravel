<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\ListQuery;
use PHPUnit\Framework\TestCase;

final class ListQueryTest extends TestCase
{
    private function product(): Descriptor
    {
        return Descriptor::fromSpec('Product', 'name:string, notes:text?, price:money, active:boolean, kind:enum(stock|digital), categoryId:string?');
    }

    public function test_defaults_match_flare(): void
    {
        $q = ListQuery::parse($this->product(), []);

        $this->assertTrue($q->ok());
        $this->assertSame([1, 25], [$q->page, $q->perPage]);
        $this->assertSame(['field' => 'createdAt', 'column' => 'created_at', 'direction' => 'desc'], $q->sort);
    }

    public function test_parses_page_sort_search_and_filters(): void
    {
        $q = ListQuery::parse($this->product(), [
            'page' => '3', 'perPage' => '50', 'sort' => '-price', 'q' => '  mug ',
            'filter' => ['active' => 'false', 'kind' => 'digital', 'categoryId' => 'null', 'price' => '9.5'],
        ]);

        $this->assertTrue($q->ok(), json_encode($q->issues));
        $this->assertSame([3, 50, 'mug'], [$q->page, $q->perPage, $q->q]);
        $this->assertSame(['field' => 'price', 'column' => 'price', 'direction' => 'desc'], $q->sort);
        $this->assertSame([
            ['field' => 'active', 'column' => 'active', 'value' => false],
            ['field' => 'kind', 'column' => 'kind', 'value' => 'digital'],
            ['field' => 'categoryId', 'column' => 'category_id', 'value' => null],
            ['field' => 'price', 'column' => 'price', 'value' => 9.5],
        ], $q->filters);
    }

    public function test_reports_every_bad_param(): void
    {
        $q = ListQuery::parse($this->product(), [
            'page' => '0', 'perPage' => '500', 'sort' => 'notes',
            'filter' => ['secret' => '1', 'kind' => 'gold', 'active' => 'maybe', 'name' => ''],
        ]);

        $this->assertSame(['page', 'perPage', 'sort', 'filter[secret]', 'filter[kind]', 'filter[active]', 'filter[name]'], array_column($q->issues, 'param'));
    }

    private function order(): Descriptor
    {
        return Descriptor::fromSpec('Order', 'reference:string, total:money, count:int, status:enum(new|paid|sent), placedOn:date?, paidAt:datetime?, active:boolean, customerId:belongsTo(Customer)?');
    }

    public function test_one_of_several_values(): void
    {
        $q = ListQuery::parse($this->order(), ['filter' => [
            'status' => 'new, paid,new',
            'count' => '1,2',
            'customerId' => ['a', 'b'],
            'reference' => ['in' => 'A-1,A-2'],
        ]]);

        $this->assertTrue($q->ok(), json_encode($q->issues));
        $this->assertSame([
            ['field' => 'status', 'column' => 'status', 'op' => 'in', 'value' => ['new', 'paid']],
            ['field' => 'count', 'column' => 'count', 'op' => 'in', 'value' => [1, 2]],
            ['field' => 'customerId', 'column' => 'customer_id', 'op' => 'in', 'value' => ['a', 'b']],
            ['field' => 'reference', 'column' => 'reference', 'op' => 'in', 'value' => ['A-1', 'A-2']],
        ], $q->filters);
    }

    public function test_a_comma_in_a_string_is_still_one_value(): void
    {
        $q = ListQuery::parse($this->order(), ['filter' => ['reference' => 'Smith, Ada']]);

        $this->assertSame([['field' => 'reference', 'column' => 'reference', 'value' => 'Smith, Ada']], $q->filters);
    }

    public function test_numbers_by_range_and_words_within(): void
    {
        $q = ListQuery::parse($this->order(), ['filter' => [
            'total' => ['gt' => '10', 'lt' => '99.5'],
            'count' => ['between' => '2,5'],
            'reference' => ['contains' => ' a-1 '],
        ]]);

        $this->assertTrue($q->ok(), json_encode($q->issues));
        $this->assertSame([
            ['field' => 'total', 'column' => 'total', 'op' => 'range', 'value' => [['>', 10.0]]],
            ['field' => 'total', 'column' => 'total', 'op' => 'range', 'value' => [['<', 99.5]]],
            ['field' => 'count', 'column' => 'count', 'op' => 'range', 'value' => [['>=', 2], ['<=', 5]]],
            ['field' => 'reference', 'column' => 'reference', 'op' => 'contains', 'value' => 'a-1'],
        ], $q->filters);
    }

    public function test_a_date_is_a_whole_day_and_a_time_is_read_in_the_apps_timezone(): void
    {
        $q = ListQuery::parse($this->order(), ['filter' => [
            'placedOn' => ['between' => '2026-11-01,2026-11-30'],
            'paidAt' => ['gt' => '2026-11-20', 'lt' => '2026-11-22', 'between' => '2026-11-01T09:00:00Z,2026-11-02'],
            'createdAt' => ['gt' => '2026-11-20T12:00'],
        ]], 25, 100, 'Africa/Kampala');

        $this->assertTrue($q->ok(), json_encode($q->issues));
        $shown = array_map(fn (array $f) => [$f['field'], array_map(fn (array $c) => [$c[0], $c[1] instanceof \DateTimeInterface ? $c[1]->format('Y-m-d H:i:s P') : $c[1]], $f['value'])], $q->filters);
        $this->assertSame([
            ['placedOn', [['>=', '2026-11-01'], ['<=', '2026-11-30']]],
            // After the 20th: from the start of the 21st.
            ['paidAt', [['>=', '2026-11-21 00:00:00 +03:00']]],
            ['paidAt', [['<', '2026-11-22 00:00:00 +03:00']]],
            // 9 in the morning UTC is noon in Kampala; the 2nd is all of the 2nd.
            ['paidAt', [['>=', '2026-11-01 12:00:00 +03:00'], ['<', '2026-11-03 00:00:00 +03:00']]],
            ['createdAt', [['>', '2026-11-20 12:00:00 +03:00']]],
        ], $shown);
        $this->assertSame('created_at', $q->filters[4]['column']);
    }

    public function test_an_operator_the_field_cannot_answer_is_refused(): void
    {
        $q = ListQuery::parse($this->order(), ['filter' => [
            'status' => ['gt' => 'new'],
            'active' => ['in' => 'true,false'],
            'reference' => ['between' => 'a,b'],
            'total' => ['between' => '10', 'nope' => '1', 'gt' => 'ten'],
            'placedOn' => ['gt' => '2026-11-01T10:00', 'lt' => '2026-02-30'],
            'paidAt' => ['between' => '2026-11-05,2026-11-01'],
            'count' => ['in' => implode(',', range(1, 101))],
            'createdAt' => '2026-11-01',
            'customerId' => 'a,null',
        ]]);

        $this->assertSame([
            'filter[status][gt]', 'filter[active][in]', 'filter[reference][between]',
            'filter[total][between]', 'filter[total][nope]', 'filter[total][gt]',
            'filter[placedOn][gt]', 'filter[placedOn][lt]', 'filter[paidAt][between]',
            'filter[count][in]', 'filter[createdAt]', 'filter[customerId][in]',
        ], array_column($q->issues, 'param'));
        $this->assertSame([], $q->filters);
    }

    public function test_items_are_included_only_where_there_are_lines(): void
    {
        $order = Descriptor::fromJson((string) json_encode([...$this->order()->toArray(), 'items' => 'OrderItem']));

        $this->assertSame(['items'], ListQuery::parse($order, ['include' => 'items'])->include);
        $this->assertSame(['include'], array_column(ListQuery::parse($this->order(), ['include' => 'items'])->issues, 'param'));
        $this->assertSame(['include'], array_column(ListQuery::parse($order, ['include' => 'customer'])->issues, 'param'));
    }
}
