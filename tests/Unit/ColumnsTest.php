<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use PHPUnit\Framework\TestCase;

final class ColumnsTest extends TestCase
{
    public function test_long_text_and_files_start_hidden_and_the_rest_is_left_to_the_dashboard(): void
    {
        $listing = fn (string $token) => Field::parse($token)->listing();

        $this->assertSame('hidden', $listing('notes:text?'));
        $this->assertSame('hidden', $listing('body:richtext'));
        $this->assertSame('hidden', $listing('manual:file(pdf)?'));
        $this->assertSame('hidden', $listing('excerpt:string(500)?'));
        $this->assertSame('hidden', Field::fromArray('photo', ['kind' => 'file', 'accept' => ['image'], 'private' => true])->listing());
        $this->assertSame('auto', $listing('image:image?'));
        $this->assertSame('auto', $listing('name:string'));
        $this->assertSame('auto', $listing('code:string(40)'));
    }

    public function test_the_descriptor_decides_when_it_says(): void
    {
        $this->assertSame('shown', Field::fromArray('notes', ['kind' => 'text', 'list' => true])->listing());
        $this->assertSame('hidden', Field::fromArray('email', ['kind' => 'string', 'list' => 'hidden'])->listing());
        $this->assertSame('none', Field::fromArray('image', ['kind' => 'file', 'accept' => ['image'], 'list' => false])->listing());
        // Kept as it was written, so a descriptor read and written back doesn't change.
        $this->assertSame('hidden', Field::fromArray('email', ['kind' => 'string', 'list' => 'hidden'])->toArray()['list']);
        $this->assertArrayNotHasKey('list', Field::parse('name:string')->toArray());

        $this->expectException(InvalidArgumentException::class);
        Field::fromArray('email', ['kind' => 'string', 'list' => 'sometimes']);
    }

    public function test_the_dashboard_is_told_what_was_decided(): void
    {
        $resource = Descriptor::fromArray([
            'name' => 'Contact',
            'fields' => [
                'name' => ['kind' => 'string'],
                'email' => ['kind' => 'string', 'format' => 'email', 'list' => 'hidden'],
                'notes' => ['kind' => 'text', 'required' => false],
                'summary' => ['kind' => 'text', 'list' => true],
                'secret' => ['kind' => 'string', 'list' => false],
            ],
        ]);
        $typescript = (new ResourceGenerator)->typescript($resource);

        $this->assertStringContainsString('name: field.string(),', $typescript);
        $this->assertStringContainsString('email: field.email({ list: true, ...({ column: "hidden" } as {}) }),', $typescript);
        $this->assertStringContainsString('notes: field.text({ required: false, list: true, ...({ column: "hidden" } as {}) }),', $typescript);
        $this->assertStringContainsString('summary: field.text({ list: true, ...({ column: "shown" } as {}) }),', $typescript);
        $this->assertStringContainsString('secret: field.string({ list: false }),', $typescript);
    }
}
