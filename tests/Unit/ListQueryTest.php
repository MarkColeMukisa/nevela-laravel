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
}
