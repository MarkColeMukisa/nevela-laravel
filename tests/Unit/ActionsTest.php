<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Action;
use Nevela\Laravel\Support\Descriptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The "actions" section of a descriptor: what it may say, and what is generated from it. */
final class ActionsTest extends TestCase
{
    /** @param array<string, mixed> $actions */
    private function application(array $actions): Descriptor
    {
        return Descriptor::fromArray([
            'name' => 'InternshipApplication',
            'fields' => ['name' => ['kind' => 'string'], 'acceptedAt' => ['kind' => 'datetime', 'required' => false], 'status' => ['kind' => 'enum', 'options' => ['new', 'seen']], 'paid' => ['kind' => 'boolean']],
            'actions' => $actions,
        ]);
    }

    public function test_an_action_is_read_from_the_descriptor_and_written_back_as_it_was(): void
    {
        $actions = [
            'accept' => ['label' => 'Accept', 'icon' => 'check', 'on' => 'record', 'confirm' => 'Send the acceptance letter to this applicant?', 'when' => ['acceptedAt' => null], 'many' => true],
            'sendAnnouncement' => ['label' => 'Send announcement', 'on' => 'collection', 'fields' => ['subject' => ['kind' => 'string'], 'message' => ['kind' => 'text', 'required' => false]]],
        ];
        $descriptor = $this->application($actions);

        $this->assertSame($actions, $descriptor->toArray()['actions']);
        $this->assertEquals($descriptor, Descriptor::fromJson($descriptor->toJson()));

        $announce = $descriptor->actions['sendAnnouncement'];
        $this->assertSame('send_announcement', $announce->key());
        $this->assertSame('SendAnnouncement', $announce->className());
        // What it asks for is checked as a field of that kind is.
        $this->assertSame(['subject' => ['required', 'string', 'max:255'], 'message' => ['nullable', 'string', 'max:65535']], $announce->rules());

        // With nothing said, it is on a record and called by its name; a descriptor with none says nothing of them.
        $plain = Action::fromArray('markSeen', []);
        $this->assertSame(['Mark seen', 'record', false], [$plain->label, $plain->on, $plain->many]);
        $this->assertArrayNotHasKey('actions', $this->application([])->toArray());
    }

    public function test_when_says_which_records_it_applies_to(): void
    {
        $accept = Action::fromArray('accept', ['when' => ['acceptedAt' => null, 'status' => 'new', 'paid' => true]]);

        $this->assertTrue($accept->applies(['acceptedAt' => null, 'status' => 'new', 'paid' => true]));
        // A yes/no column comes back from some databases as a number.
        $this->assertTrue($accept->applies(['acceptedAt' => null, 'status' => 'new', 'paid' => 1]));
        $this->assertFalse($accept->applies(['acceptedAt' => '2026-10-11 09:00:00', 'status' => 'new', 'paid' => true]));
        $this->assertFalse($accept->applies(['acceptedAt' => null, 'status' => 'seen', 'paid' => true]));
        $this->assertFalse($accept->applies(['acceptedAt' => null, 'status' => 'new', 'paid' => 0]));
        $this->assertFalse($accept->applies(['acceptedAt' => null, 'status' => null, 'paid' => true]));
        // With no conditions it applies to anything.
        $this->assertTrue(Action::fromArray('archive', [])->applies([]));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function mistakes(): iterable
    {
        yield 'a name that is not camelCase' => [['send_now' => []], 'must be camelCase'];
        yield 'a name every resource has' => [['delete' => []], 'every resource does already'];
        yield 'somewhere that is not a place' => [['accept' => ['on' => 'row']], 'Use record or collection'];
        yield 'a condition on the whole list' => [['announce' => ['on' => 'collection', 'when' => ['status' => 'new']]], 'no one record'];
        yield 'a selection of the whole list' => [['announce' => ['on' => 'collection', 'many' => true]], 'no one record'];
        yield 'a condition on a field it does not have' => [['accept' => ['when' => ['approvedAt' => null]]], 'not one of its fields'];
        yield 'a condition that is a list' => [['accept' => ['when' => ['status' => ['new', 'seen']]]], 'one value, or with null'];
        yield 'asking for a file' => [['accept' => ['fields' => ['letter' => ['kind' => 'file', 'accept' => ['pdf']]]]], "isn't something a button asks for"];
        yield 'asking for a relation' => [['accept' => ['fields' => ['reviewerId' => ['kind' => 'belongsTo', 'target' => 'User']]]], "isn't something a button asks for"];
        yield 'two that are the same permission' => [['sendNow' => [], 'sendNOW' => []], 'two actions that come to "send_now"'];
    }

    /** @param array<string, mixed> $actions */
    #[DataProvider('mistakes')]
    public function test_a_mistake_in_the_section_is_refused_with_what_is_wrong(array $actions, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->application($actions);
    }

    public function test_each_action_gets_a_class_written_once(): void
    {
        $descriptor = $this->application([
            'accept' => ['label' => 'Accept', 'when' => ['acceptedAt' => null]],
            'sendAnnouncement' => ['label' => 'Send announcement', 'on' => 'collection', 'fields' => ['subject' => ['kind' => 'string']]],
        ]);
        $generator = new ResourceGenerator;
        $files = [];
        foreach ($generator->forResource($descriptor, '2026_10_02_000000') as $file) {
            $files[$file->path] = $file;
        }

        $accept = $files['app/Nevela/Actions/InternshipApplication/Accept.php'];
        $this->assertSame('once', $accept->mode);
        $this->assertStringContainsString('namespace App\Nevela\Actions\InternshipApplication;', $accept->contents);
        $this->assertStringContainsString('use App\Models\InternshipApplication;', $accept->contents);
        $this->assertStringContainsString('public function handle(InternshipApplication $internshipApplication, array $input): ?string', $accept->contents);
        $this->assertStringContainsString('"internship_applications.accept"', $accept->contents);

        $announce = $files['app/Nevela/Actions/InternshipApplication/SendAnnouncement.php'];
        $this->assertStringContainsString('public function handle(array $input): ?string', $announce->contents);
        $this->assertStringNotContainsString('use App\Models', $announce->contents);

        foreach ([$accept, $announce] as $file) {
            $path = sys_get_temp_dir().'/nevela-action-'.bin2hex(random_bytes(4)).'.php';
            file_put_contents($path, $file->contents);
            exec('php -l '.escapeshellarg($path).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }
        // A resource with none has the files it always had.
        $this->assertCount(15, $generator->forResource($this->application([]), '2026_10_02_000000'));
    }

    public function test_the_dashboard_is_told_of_them_in_the_registry(): void
    {
        $generator = new ResourceGenerator;
        $plain = Descriptor::fromSpec('Category', 'name:string');
        $with = $this->application(['accept' => ['label' => 'Accept', 'icon' => 'check', 'confirm' => 'Sure?', 'when' => ['acceptedAt' => null]]]);

        $registry = $generator->registry([$plain, $with])->contents;
        $this->assertStringContainsString('export const extras: Record<string, unknown> = {', $registry);
        $this->assertStringContainsString('"InternshipApplication": {', $registry);
        $this->assertStringContainsString('"permission": "internship_applications.accept"', $registry);
        $this->assertStringContainsString('"acceptedAt": null', $registry);
        $this->assertStringNotContainsString('"Category"', $registry);

        // With no actions anywhere, and with no resources at all, there is still something to import.
        $this->assertStringContainsString('export const extras: Record<string, unknown> = {};', $generator->registry([$plain])->contents);
        $this->assertStringContainsString("export const resources = [] as const;\n\n", $generator->registry([])->contents);
        $this->assertStringContainsString('export const extras: Record<string, unknown> = {};', $generator->registry([])->contents);
    }
}
