<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Fake;
use Nevela\Laravel\Support\Html;
use Nevela\Laravel\Support\ListQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A rich text field: formatted text kept as HTML, of which nothing sent is trusted. */
final class RichTextTest extends TestCase
{
    private function post(): Descriptor
    {
        return Descriptor::fromSpec('Post', 'title:string, body:richtext, summary:richtext(20000)?, notes:text?');
    }

    /** @return iterable<string, array{string, string}> */
    public static function html(): iterable
    {
        yield 'what the issue asks' => ['<p onclick="x()">Hi<script>alert(1)</script></p>', '<p>Hi</p>'];
        yield 'everything the toolbar makes' => [
            '<h2>Title</h2><h3>Sub</h3><p><strong>b</strong> <em>i</em> <s>s</s> <a href="https://example.com/a?b=1&amp;c=2">link</a><br>next</p><ul><li>one</li></ul><ol><li>two</li></ol><blockquote><p>quoted</p></blockquote>',
            '<h2>Title</h2><h3>Sub</h3><p><strong>b</strong> <em>i</em> <s>s</s> <a href="https://example.com/a?b=1&amp;c=2">link</a><br>next</p><ul><li>one</li></ul><ol><li>two</li></ol><blockquote><p>quoted</p></blockquote>',
        ];
        yield 'other spellings of the same tags' => ['<p><b>b</b><i>i</i><strike>s</strike><del>d</del></p>', '<p><strong>b</strong><em>i</em><s>s</s><s>d</s></p>'];
        yield 'a tag that is not kept leaves its words' => ['<p>one <span style="color:red">two</span> <u>three</u> <code>four</code></p>', '<p>one two three four</p>'];
        yield 'attributes go' => ['<p class="x" style="y" id="z" data-a="b">text</p><a href="https://a.test" target="_blank" onclick="x()" class="btn">go</a>', '<p>text</p><p><a href="https://a.test">go</a></p>'];
        yield 'a link to a script is its words' => ['<p><a href="javascript:alert(1)">click</a></p>', '<p>click</p>'];
        yield 'however the scheme is spelled' => ["<p><a href=\"JaVa\tScRiPt:alert(1)\">a</a><a href=\" &#106;avascript:alert(1)\">b</a><a href=\"data:text/html,x\">c</a><a href=\"vbscript:x\">d</a></p>", '<p>abcd</p>'];
        yield 'links that are allowed' => ['<p><a href="mailto:a@b.test">m</a><a href="/about">r</a><a href="#top">h</a><a href="http://a.test">p</a></p>', '<p><a href="mailto:a@b.test">m</a><a href="/about">r</a><a href="#top">h</a><a href="http://a.test">p</a></p>'];
        yield 'a link with nowhere to go' => ['<p><a name="x">anchor</a></p>', '<p>anchor</p>'];
        yield 'what was never text goes with its contents' => ['<p>a</p><style>p{color:red}</style><iframe src="https://evil.test"></iframe><svg><script>alert(1)</script></svg><object data="x"></object><p>b</p>', '<p>a</p><p>b</p>'];
        yield 'pictures are not on the list' => ['<p>a<img src="x" onerror="alert(1)">b</p>', '<p>ab</p>'];
        yield 'comments' => ['<p>a<!-- <script>alert(1)</script> -->b</p>', '<p>ab</p>'];
        yield 'plain text becomes a paragraph' => ['Hello & welcome', '<p>Hello &amp; welcome</p>'];
        yield 'loose text beside a paragraph' => ['Hello <b>there</b><p>Second</p>and third', '<p>Hello <strong>there</strong></p><p>Second</p><p>and third</p>'];
        yield 'a wrapper is looked into' => ['<div><p>one</p><div>two</div><section><h2>three</h2></section></div>', '<p>one</p><p>two</p><h2>three</h2>'];
        yield 'a heading the toolbar has no button for' => ['<h1>Big</h1><p>text</p>', '<p>Big</p><p>text</p>'];
        yield 'text is written out as text' => ['<p>1 &lt; 2 &amp;&amp; "quoted" &lt;script&gt;</p>', '<p>1 &lt; 2 &amp;&amp; "quoted" &lt;script&gt;</p>'];
        yield 'other alphabets and emoji' => ['<p>Kampala — Ünïcödé 日本語 🎉</p>', '<p>Kampala — Ünïcödé 日本語 🎉</p>'];
        yield 'broken markup is closed' => ['<p>one<strong>two', '<p>one<strong>two</strong></p>'];
        yield 'an editor opened and left' => ['<p><br></p>', ''];
        yield 'spaces and nothing else' => [" <p> &nbsp; </p>\n<div></div>", ''];
        yield 'nothing' => ['', ''];
    }

    #[DataProvider('html')]
    public function test_html_is_written_out_again_with_only_what_is_allowed(string $sent, string $kept): void
    {
        $this->assertSame($kept, Html::clean($sent));
        // Cleaning what is clean changes nothing: a record can be read and saved for ever.
        $this->assertSame($kept, Html::clean($kept));
    }

    public function test_the_list_of_what_is_allowed_can_be_changed(): void
    {
        $allow = ['tags' => ['p', 'a', 'img', 'u'], 'attributes' => ['a' => ['href', 'title'], 'img' => ['src', 'alt']], 'schemes' => ['https']];

        $this->assertSame(
            '<p><u>u</u> b <a href="https://a.test" title="T">l</a> m <img src="https://a.test/i.png" alt="A"><img alt="B"></p>',
            Html::clean('<p><u>u</u> <strong>b</strong> <a href="https://a.test" title="T" rel="x">l</a> <a href="http://a.test">m</a> <img src="https://a.test/i.png" alt="A" onerror="x"><img src="javascript:x" alt="B"></p>', $allow),
        );
        // A picture on its own is something to keep.
        $this->assertSame('<p><img src="/a.png"></p>', Html::clean('<img src="/a.png">', $allow));
    }

    public function test_the_words_alone_for_a_table_cell(): void
    {
        $html = '<h2>Opening day</h2><p>We are <strong>open</strong>.<br>Come in &amp; look.</p><ul><li>one</li><li>two</li></ul>';

        $this->assertSame('Opening day We are open. Come in & look. one two', Html::text($html));
        $this->assertSame('Opening day We…', Html::text($html, 15));
        $this->assertSame('', Html::text(null));
    }

    public function test_it_is_text_with_a_format_and_takes_a_length(): void
    {
        $post = $this->post();

        $this->assertSame(['kind' => 'text', 'format' => 'rich'], $post->fields['body']->toArray());
        $this->assertTrue($post->fields['body']->isRich());
        $this->assertFalse($post->fields['notes']->isRich());
        $this->assertSame(20000, $post->fields['summary']->maxLength);
        $this->assertTrue(Descriptor::fromJson($post->toJson())->fields['body']->isRich());

        // Like any long text, it is not sorted by, filtered on or searched.
        $this->assertFalse($post->fields['body']->sortable());
        $query = ListQuery::parse($post, ['sort' => 'body', 'filter' => ['body' => 'x']]);
        $this->assertSame(['sort', 'filter[body]'], array_column($query->issues, 'param'));
    }

    public function test_what_is_generated_for_it(): void
    {
        $generator = new ResourceGenerator;
        $post = $this->post();

        // Room for the tags as well as the words.
        $migration = $generator->migration($post);
        $this->assertStringContainsString("\$table->longText('body');", $migration);
        $this->assertStringContainsString("\$table->longText('summary')->nullable();", $migration);
        $this->assertStringContainsString("\$table->text('notes')->nullable();", $migration);

        // The request says which fields to clean, and checks the length of what is kept.
        $request = $generator->request($post);
        $this->assertStringContainsString("'body' => ['required', 'string', 'max:65535'],", $request);
        $this->assertStringContainsString("'summary' => ['nullable', 'string', 'max:20000'],", $request);
        $this->assertStringContainsString("protected function rich(): array\n    {\n        return ['body', 'summary'];\n    }\n    // nevela:generated:end", $request);

        // Cleaned on the way out too.
        $resource = $generator->resource($post);
        $this->assertStringContainsString("'body' => \\Nevela\\Laravel\\Nevela::html(\$this->body),", $resource);
        $this->assertStringContainsString("'notes' => \$this->notes,", $resource);

        // And the dashboard is told, in a way Flare's types let through.
        $typescript = $generator->typescript($post);
        $this->assertStringContainsString('body: field.text({ ...({ rich: true } as {}) }),', $typescript);
        $this->assertStringContainsString('summary: field.text({ required: false, maxLength: 20000, ...({ rich: true } as {}) }),', $typescript);
        $this->assertStringContainsString('notes: field.text({ required: false }),', $typescript);

        // A resource without one has the request it always had.
        $this->assertStringNotContainsString('rich()', $generator->request(Descriptor::fromSpec('Note', 'body:text')));
    }

    public function test_a_seeded_record_reads_like_one_somebody_wrote(): void
    {
        $html = Fake::row($this->post(), 1)['body'];

        $this->assertStringContainsString('<h2>', $html);
        $this->assertStringContainsString('<ul><li>', $html);
        $this->assertGreaterThanOrEqual(3, substr_count($html, '<p>'));
        // What the seeder writes is what the cleaner would keep.
        $this->assertSame($html, Html::clean($html));
    }
}
