<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Fake;
use Nevela\Laravel\Support\Field;
use PHPUnit\Framework\TestCase;

/** A field of several files: gallery:files(image), as Grit writes it. */
final class GalleriesTest extends TestCase
{
    public function test_it_is_written_the_ways_grit_and_flare_write_it(): void
    {
        foreach (['gallery:files(image)', 'gallery:files[image]', 'gallery:files:image', 'gallery:images'] as $written) {
            $field = Field::parse($written);
            $this->assertTrue($field->multiple, $written);
            $this->assertSame(['image'], $field->accept, $written);
            $this->assertTrue($field->isImage(), $written);
        }
        $this->assertSame('product', Field::parse('gallery:images(product)')->profile);
        $this->assertSame(['pdf', 'document'], Field::parse('docs:files[pdf, document]?')->accept);
        $this->assertFalse(Field::parse('photo:image')->multiple);

        $field = Field::parse('gallery:files(image)?');
        $this->assertSame(['kind' => 'file', 'multiple' => true, 'accept' => ['image'], 'required' => false], $field->toArray());
        $this->assertEquals($field, Field::fromArray('gallery', $field->toArray()));
    }

    public function test_what_it_can_t_be_is_said_when_the_descriptor_is_read(): void
    {
        $refused = [
            'private' => [fn () => Field::parse('scans:files(pdf|private)'), 'both several files and private'],
            'not files' => [fn () => Field::fromArray('tags', ['kind' => 'string', 'multiple' => true]), 'that is for files'],
            'read publicly' => [fn () => Descriptor::fromArray(['name' => 'Project', 'fields' => ['name' => ['kind' => 'string'], 'gallery' => ['kind' => 'file', 'accept' => ['image'], 'multiple' => true]], 'public' => ['read' => ['fields' => ['name', 'gallery']]]]), 'holds several files. The public API doesn\'t read those yet'],
            'sent publicly' => [fn () => Descriptor::fromArray(['name' => 'Project', 'fields' => ['name' => ['kind' => 'string'], 'gallery' => ['kind' => 'file', 'accept' => ['image'], 'multiple' => true]], 'public' => ['create' => ['fields' => ['name', 'gallery']]]]), 'doesn\'t create those yet'],
        ];
        foreach ($refused as $case => [$make, $message]) {
            try {
                $make();
                $this->fail("{$case} was taken");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $case);
            }
        }
    }

    public function test_what_is_generated_keeps_a_list_of_keys_each_checked_as_a_file_s_is(): void
    {
        $project = Descriptor::fromSpec('Project', 'name:string, gallery:files[image], docs:files(pdf)?');
        $generator = new ResourceGenerator;

        $migration = $generator->migration($project);
        $this->assertStringContainsString("\$table->json('gallery');", $migration);
        $this->assertStringContainsString("\$table->json('docs')->nullable();", $migration);

        $request = $generator->request($project);
        $this->assertStringContainsString("'gallery' => ['required', 'list', 'max:20'],", $request);
        $this->assertStringContainsString("'gallery.*' => ['string', 'distinct', 'max:512', new \\Nevela\\Laravel\\Rules\\UploadKey('Project', 'gallery')],", $request);
        $this->assertStringContainsString("'docs' => ['nullable', 'list', 'max:20'],", $request);

        $this->assertStringContainsString("'gallery' => 'array',", $generator->model($project));
        $this->assertStringContainsString("'galleryFiles' => array_map(fn (string \$key) => \\Nevela\\Laravel\\Nevela::file(\$key), \$this->gallery ?? []),", $generator->resource($project));
        $this->assertStringContainsString('gallery: field.json({ list: true, sortable: false, ...({ files: ["image"] } as {}) }),', $generator->typescript($project));
    }

    public function test_a_seeded_gallery_is_a_few_different_pictures_written_as_its_column_holds_them(): void
    {
        $project = Descriptor::fromSpec('Project', 'name:string, gallery:files(image)');
        $pool = ['a.webp', 'b.webp', 'c.webp', 'd.webp', 'e.webp', 'f.webp'];
        $sizes = [];
        foreach (range(1, 100) as $number) {
            $keys = json_decode((string) Fake::row($project, $number, ['gallery' => $pool])['gallery'], true);
            $this->assertSame($keys, array_values(array_unique($keys)));
            $this->assertSame([], array_diff($keys, $pool));
            $sizes[count($keys)] = true;
        }
        ksort($sizes);
        $this->assertSame([1, 2, 3, 4], array_keys($sizes));
        // Fewer pictures than that to choose from, fewer in each.
        $this->assertSame(['a.webp'], json_decode((string) Fake::row($project, 1, ['gallery' => ['a.webp']])['gallery'], true));
    }
}
