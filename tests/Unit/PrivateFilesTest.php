<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use PHPUnit\Framework\TestCase;

/** A file field marked private: how it is written, and what is generated for it. */
final class PrivateFilesTest extends TestCase
{
    private function candidate(): Descriptor
    {
        return Descriptor::fromSpec('Candidate', 'name:string, cv:file(pdf|document|private)?, photo:file(private), brochure:file(pdf)?, portrait:image?');
    }

    public function test_private_is_said_among_what_the_field_takes_and_is_not_one_of_them(): void
    {
        $fields = $this->candidate()->fields;

        $this->assertTrue($fields['cv']->private);
        $this->assertSame(['pdf', 'document'], $fields['cv']->accept);
        // With nothing else said, it takes anything.
        $this->assertTrue($fields['photo']->private);
        $this->assertSame(['any'], $fields['photo']->accept);
        $this->assertFalse($fields['brochure']->private);
        $this->assertFalse($fields['portrait']->private);

        $this->assertSame(['kind' => 'file', 'accept' => ['pdf', 'document'], 'private' => true, 'required' => false], $fields['cv']->toArray());
        $this->assertArrayNotHasKey('private', $fields['brochure']->toArray());
        $this->assertTrue(Descriptor::fromJson($this->candidate()->toJson())->fields['cv']->private);
        // Grit's and Flare's spellings of a file field say it the same way.
        $this->assertTrue(Field::parse('cv:file:[pdf, private]')->private);
    }

    public function test_only_a_file_can_be_private(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("can't be private");
        Field::fromArray('salary', ['kind' => 'int', 'private' => true]);
    }

    public function test_a_private_key_says_so_itself(): void
    {
        $this->assertTrue(Uploads::isPrivate('private/candidates/cv/2026/10/abc-cv.pdf'));
        $this->assertFalse(Uploads::isPrivate('candidates/brochure/2026/10/abc-b.pdf'));
        $this->assertFalse(Uploads::isPrivate('candidates/private/2026/10/abc.pdf'));
        $this->assertFalse(Uploads::isPrivate(null));
        $this->assertSame('nevela/private/candidates/cv/x.pdf', Uploads::privatePath('private/candidates/cv/x.pdf'));
    }

    public function test_what_is_generated_for_it(): void
    {
        $generator = new ResourceGenerator;
        $candidate = $this->candidate();

        // Its details name the record it is fetched through. A public file's are worked out from its key.
        $resource = $generator->resource($candidate);
        $this->assertStringContainsString("'cv' => \$this->cv,", $resource);
        $this->assertStringContainsString("'cvFile' => \\Nevela\\Laravel\\Nevela::privateFile(\$this->cv, 'candidates', \$this->getKey(), 'cv'),", $resource);
        $this->assertStringContainsString("'brochureFile' => \\Nevela\\Laravel\\Nevela::file(\$this->brochure),", $resource);

        // The same rule as any file: the key has to be one uploaded to this field.
        $this->assertStringContainsString("'cv' => ['nullable', 'string', 'max:512', new \\Nevela\\Laravel\\Rules\\UploadKey('Candidate', 'cv')],", $generator->request($candidate));

        $typescript = $generator->typescript($candidate);
        $this->assertStringContainsString('cv: field.file(["pdf","document"], { required: false, list: true, ...({ private: true } as {}), ...({ column: "hidden" } as {}) }),', $typescript);
        $this->assertStringContainsString('photo: field.file(["any"], { list: true, ...({ private: true } as {}), ...({ column: "hidden" } as {}) }),', $typescript);
        $this->assertStringContainsString('brochure: field.file(["pdf"], { required: false, list: true, ...({ column: "hidden" } as {}) }),', $typescript);
        $this->assertStringContainsString('portrait: field.file(["image"], { required: false, list: true }),', $typescript);

        // A resource with no private file has the API resource it always had.
        $this->assertStringNotContainsString('privateFile', $generator->resource(Descriptor::fromSpec('Product', 'name:string, image:image?')));
    }
}
