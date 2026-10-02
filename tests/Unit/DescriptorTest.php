<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Naming;
use PHPUnit\Framework\TestCase;

final class DescriptorTest extends TestCase
{
    public function test_parses_a_field_spec_into_flare_kinds(): void
    {
        $d = Descriptor::fromSpec('BlogPost', 'title:string, slug:slug!, body:text?, status:enum(draft|live), price:money, publishedAt:datetime?, contact_email:email?');

        $this->assertSame('blog_posts', $d->table);
        $this->assertSame('blog-posts', $d->slug);
        $this->assertSame('Blog posts', $d->pluralLabel);
        $this->assertSame('blog_post', $d->routeParameter());
        $this->assertSame(['title', 'slug', 'body', 'status', 'price', 'publishedAt', 'contactEmail'], array_keys($d->fields));
        $this->assertSame('slug', $d->fields['slug']->format);
        $this->assertTrue($d->fields['slug']->unique);
        $this->assertFalse($d->fields['body']->required);
        $this->assertSame(['draft', 'live'], $d->fields['status']->options);
        $this->assertSame(['float', 'money'], [$d->fields['price']->kind, $d->fields['price']->format]);
        $this->assertSame('published_at', $d->fields['publishedAt']->column());
        $this->assertSame('title', $d->title());
    }

    public function test_round_trips_through_json(): void
    {
        $d = Descriptor::fromSpec('Product', 'name:string, active:boolean, kind:enum(stock|digital)?', 'package', 'Catalogue');
        $again = Descriptor::fromJson($d->toJson());

        $this->assertSame($d->toArray(), $again->toArray());
        $this->assertSame('package', $again->icon);
    }

    public function test_rejects_reserved_and_unknown_fields(): void
    {
        foreach (['createdAt:datetime', 'name:blob', 'status:enum()', 'bad field'] as $spec) {
            try {
                Descriptor::fromSpec('Product', $spec);
                $this->fail("Accepted {$spec}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_pluralises_like_flare(): void
    {
        $this->assertSame('categories', Naming::plural('category'));
        $this->assertSame('Boxes', Naming::plural('Box'));
        $this->assertSame('people', Naming::plural('person'));
        $this->assertSame('Days', Naming::plural('Day'));
    }
}
