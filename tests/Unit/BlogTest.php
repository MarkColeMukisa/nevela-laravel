<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\BlogPages;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use Nevela\Laravel\Support\ListQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What a blog is made of: a slug that fills itself, a date a record is published by, and the pages. */
final class BlogTest extends TestCase
{
    /** @param array<string, mixed> $over */
    private function post(array $over = []): Descriptor
    {
        return Descriptor::fromArray($over + [
            'name' => 'Post',
            'publish' => 'publishedAt',
            'blog' => '/blog',
            'fields' => [
                'title' => ['kind' => 'string'],
                'slug' => ['kind' => 'string', 'format' => 'slug', 'unique' => true, 'from' => 'title'],
                'excerpt' => ['kind' => 'string', 'maxLength' => 500, 'required' => false],
                'body' => ['kind' => 'text', 'format' => 'rich'],
                'image' => ['kind' => 'file', 'accept' => ['image'], 'profile' => 'cover', 'required' => false],
                'publishedAt' => ['kind' => 'datetime', 'required' => false],
            ],
            'public' => ['read' => ['by' => 'slug', 'fields' => ['title', 'slug', 'excerpt', 'body', 'image', 'publishedAt']]],
        ]);
    }

    public function test_a_slug_says_which_field_it_is_made_from(): void
    {
        $field = Field::parse('slug:slug(title)!');
        $this->assertSame(['string', 'slug', 'title', true, true], [$field->kind, $field->format, $field->from, $field->unique, $field->required]);
        $this->assertSame(['kind' => 'string', 'format' => 'slug', 'from' => 'title', 'unique' => true], $field->toArray());
        // A column's name is understood too, and a number in the same place is still a length.
        $this->assertSame('pageTitle', Field::parse('slug:slug(page_title)')->from);
        $this->assertNull(Field::parse('slug:slug(100)')->from);
        $this->assertSame(100, Field::parse('slug:slug(100)')->maxLength);
        $this->assertNull(Field::parse('slug:slug!')->from);

        $post = $this->post();
        $this->assertSame('title', Descriptor::fromJson($post->toJson())->fields['slug']->from);
    }

    public function test_what_is_generated_for_a_slug_that_fills_itself(): void
    {
        $generator = new ResourceGenerator;
        $post = $this->post();

        // It may be left empty, and the request knows where to make it from. The column still can't be empty.
        $request = $generator->request($post);
        $this->assertStringContainsString("'slug' => ['nullable', 'string', 'alpha_dash', 'max:255', Rule::unique('posts', 'slug')->ignore(\$post)],", $request);
        $this->assertStringContainsString("protected function made(): array\n    {\n        return ['slug' => ['from' => 'title', 'table' => 'posts', 'column' => 'slug']];\n    }", $request);
        $this->assertStringContainsString("\$table->string('slug')->unique();", $generator->migration($post));
        // Optional in the dashboard's form, which is told what it follows.
        $this->assertStringContainsString('slug: field.slug({ required: false, unique: true, ...({ from: "title" } as {}) }),', $generator->typescript($post));

        // A slug that is typed by hand is as it always was.
        $plain = Descriptor::fromSpec('Category', 'name:string, slug:slug!');
        $this->assertStringContainsString("'slug' => ['required', 'string', 'alpha_dash', 'max:255',", $generator->request($plain));
        $this->assertStringNotContainsString('made()', $generator->request($plain));
        $this->assertStringContainsString('slug: field.slug({ unique: true }),', $generator->typescript($plain));
    }

    public function test_a_resource_is_published_by_a_date(): void
    {
        $post = $this->post();
        $this->assertSame('publishedAt', $post->publish);
        $this->assertSame('publishedAt', $post->toArray()['publish']);
        $this->assertArrayNotHasKey('publish', Descriptor::fromSpec('Note', 'body:text')->toArray());

        // Its public records are the ones whose time has come, not "none until someone says".
        $model = (new ResourceGenerator)->model($post);
        $this->assertStringContainsString("\$query->whereNotNull('published_at')->where('published_at', '<=', now());", $model);
        $this->assertStringNotContainsString("whereRaw('1 = 0')", $model);
        $this->assertGreaterThan(strpos($model, 'nevela:generated:end'), strpos($model, 'scopePublicQuery'));

        // Published by a date, and read by nobody: no scope to write.
        $private = $this->post(['public' => null, 'blog' => null]);
        $this->assertStringNotContainsString('scopePublicQuery', (new ResourceGenerator)->model($private));
    }

    public function test_a_list_is_filtered_by_which_of_the_three_a_record_is(): void
    {
        $post = $this->post();

        foreach (ListQuery::PUBLISHED as $state) {
            $query = ListQuery::parse($post, ['filter' => ['publishedAt' => $state]]);
            $this->assertTrue($query->ok());
            $this->assertSame([['field' => 'publishedAt', 'column' => 'published_at', 'value' => null, 'state' => $state]], $query->filters);
        }
        $this->assertSame('published', ListQuery::parse($post, ['filter' => ['publishedAt' => 'Published']])->filters[0]['state']);
        // It is not a date to match, on this field.
        $this->assertSame(['filter[publishedAt]'], array_column(ListQuery::parse($post, ['filter' => ['publishedAt' => '2026-10-11']])->issues, 'param'));
        // A date field of a resource that isn't published by it is filtered as before.
        $note = Descriptor::fromSpec('Note', 'body:text, remindAt:datetime?');
        $this->assertSame('2026-10-11', ListQuery::parse($note, ['filter' => ['remindAt' => '2026-10-11']])->filters[0]['value']);
    }

    public function test_the_dashboard_and_the_sites_header_are_told(): void
    {
        $registry = (new ResourceGenerator)->registry([$this->post(), Descriptor::fromSpec('Category', 'name:string')])->contents;

        $this->assertStringContainsString('"publish": "publishedAt"', $registry);
        $this->assertStringContainsString('"blog": "/blog"', $registry);
        $this->assertStringNotContainsString('"Category"', $registry);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function mistakes(): iterable
    {
        // (Nothing public and no blog, so that what is wrong is the one thing each case is about.)
        $slug = fn (array $over) => ['public' => null, 'blog' => null, 'fields' => ['title' => ['kind' => 'string'], 'views' => ['kind' => 'int'], 'slug' => $over + ['kind' => 'string', 'format' => 'slug', 'unique' => true], 'body' => ['kind' => 'text'], 'publishedAt' => ['kind' => 'datetime', 'required' => false], 'createdOn' => ['kind' => 'datetime']]];

        yield 'made from a field it does not have' => [$slug(['from' => 'headline']), 'has to be a string field'];
        yield 'made from a number' => [$slug(['from' => 'views']), 'has to be a string field'];
        yield 'made from itself' => [$slug(['from' => 'slug']), 'named as it is there'];
        yield 'something that is not a slug made from another' => [['public' => null, 'blog' => null, 'fields' => ['title' => ['kind' => 'string'], 'code' => ['kind' => 'string', 'from' => 'title'], 'publishedAt' => ['kind' => 'datetime', 'required' => false]]], 'that is for a slug'];
        yield 'published by a field it does not have' => [['publish' => 'goesLiveAt'], 'has to be an optional datetime'];
        yield 'published by a field that is not a date' => [['publish' => 'title'], 'has to be an optional datetime'];
        yield 'published by a date that is never empty' => [$slug([]) + ['publish' => 'createdOn'], 'has to be an optional datetime'];
        yield 'a blog nobody can read' => [['public' => null], 'a blog reads its posts publicly'];
        yield 'a blog with no address for a post' => [['public' => ['read' => ['fields' => ['title']]]], 'each by a unique field'];
        yield 'a blog at something that is not a path' => [['blog' => 'blog'], 'such as "/blog"'];
    }

    /** @param array<string, mixed> $over */
    #[DataProvider('mistakes')]
    public function test_a_mistake_is_refused_with_what_is_wrong(array $over, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->post($over);
    }

    public function test_the_pages_are_written_once_and_know_the_resource_only_through_one_file(): void
    {
        $fields = ['title' => 'title', 'body' => 'body', 'excerpt' => 'excerpt', 'image' => 'image', 'published' => 'publishedAt'];
        $files = [];
        foreach (BlogPages::files($this->post(), $fields) as $file) {
            $this->assertSame('web', $file->target);
            $this->assertSame('once', $file->mode);
            // Every blank in the templates was filled in.
            $this->assertDoesNotMatchRegularExpression('/__[A-Z]+__/', $file->contents, $file->path);
            $files[$file->path] = $file->contents;
        }
        $this->assertSame(['lib/blog.ts', 'app/blog/page.tsx', 'app/blog/[slug]/page.tsx', 'app/blog/feed.xml/route.ts', 'app/sitemap.ts'], array_keys($files));

        $library = $files['lib/blog.ts'];
        $this->assertStringContainsString('laravelPublic(`posts?sort=-publishedAt&page=${page}&perPage=${perPage}`', $library);
        $this->assertStringContainsString('const TAGS = [resourceTag("Post")];', $library);
        $this->assertStringContainsString('slug: String(row["slug"] ?? row.id),', $library);
        $this->assertStringContainsString('export const BODY_IS_HTML: boolean = true;', $library);
        $this->assertStringContainsString('const IMAGE_IS_UPLOAD: boolean = true;', $library);
        // The pages themselves name no field of the resource.
        $this->assertStringNotContainsString('publishedAt"', $files['app/blog/page.tsx']);
        $this->assertStringContainsString('notFound()', $files['app/blog/[slug]/page.tsx']);
        $this->assertStringContainsString('application/rss+xml', $files['app/blog/feed.xml/route.ts']);
    }

    public function test_the_home_page_shows_the_newest_posts_only_where_there_is_a_blog(): void
    {
        // No blog: the home page's import is a component that shows nothing, and nothing else is written.
        [$none] = BlogPages::homePage([Descriptor::fromSpec('Note', 'title:string')]);
        $this->assertSame('resources/latest-posts.tsx', $none->path);
        $this->assertSame('block', $none->mode);
        $this->assertStringContainsString('export function LatestPosts() {', $none->contents);
        $this->assertStringContainsString('return null;', $none->contents);
        $this->assertCount(1, BlogPages::homePage([]));

        // A blog: the import is the app's own component, written once, through the blog's library.
        [$link, $component] = BlogPages::homePage([Descriptor::fromSpec('Note', 'title:string'), $this->post()]);
        $this->assertSame('resources/latest-posts.tsx', $link->path);
        $this->assertStringContainsString('export { LatestPosts } from "@/components/marketing/latest-posts";', $link->contents);
        $this->assertSame('components/marketing/latest-posts.tsx', $component->path);
        $this->assertSame('once', $component->mode);
        $this->assertStringContainsString('await listPosts(1, 3)', $component->contents);
        $this->assertStringContainsString('href={blog.path}', $component->contents);
        $this->assertDoesNotMatchRegularExpression('/__[A-Z]+__/', $component->contents);
    }

    public function test_the_pages_for_a_table_of_the_apps_own_read_it_by_its_own_names(): void
    {
        // The table the feature was asked for, adopted: integer ids, a body that is plain text
        // until it is marked rich, and a thumbnail that is a path and not an upload.
        $adopted = Descriptor::fromArray([
            'name' => 'Article', 'table' => 'posts', 'id' => 'int', 'adopted' => true, 'publish' => 'publishedAt', 'blog' => '/blog',
            'fields' => [
                'title' => ['kind' => 'string'], 'slug' => ['kind' => 'string', 'unique' => true], 'excerpt' => ['kind' => 'string', 'maxLength' => 500],
                'body' => ['kind' => 'text'], 'thumbnail' => ['kind' => 'string', 'required' => false], 'publishedAt' => ['kind' => 'datetime', 'required' => false],
            ],
            'public' => ['read' => ['by' => 'slug', 'fields' => ['title', 'slug', 'excerpt', 'body', 'thumbnail', 'publishedAt']]],
        ]);
        $library = BlogPages::files($adopted, ['title' => 'title', 'body' => 'body', 'excerpt' => 'excerpt', 'image' => 'thumbnail', 'published' => 'publishedAt'])[0]->contents;

        $this->assertStringContainsString('laravelPublic(`articles?sort=-publishedAt', $library);
        $this->assertStringContainsString('const TAGS = [resourceTag("Article")];', $library);
        $this->assertStringContainsString('image: "thumbnail" as string | null,', $library);
        // Not HTML Laravel has cleaned, so it is shown as text; and not an upload, so it is the old app's own file.
        $this->assertStringContainsString('export const BODY_IS_HTML: boolean = false;', $library);
        $this->assertStringContainsString('const IMAGE_IS_UPLOAD: boolean = false;', $library);

        // With no picture and no date of its own, a post is still a post.
        $bare = BlogPages::files($adopted, ['title' => 'title', 'body' => 'body'])[0]->contents;
        $this->assertStringContainsString('image: null as string | null,', $bare);
        $this->assertStringContainsString('published: null as string | null,', $bare);
        $this->assertStringContainsString('sort=-createdAt', $bare);
    }
}
