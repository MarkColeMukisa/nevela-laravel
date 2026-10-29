<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Console\GenerateCommand;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use PHPUnit\Framework\TestCase;

final class UniqueTogetherTest extends TestCase
{
    private function enrolment(): Descriptor
    {
        return Descriptor::fromArray([
            'name' => 'Enrolment',
            'fields' => [
                'studentId' => ['kind' => 'belongsTo', 'target' => 'Student'],
                'courseId' => ['kind' => 'belongsTo', 'target' => 'Course'],
                'status' => ['kind' => 'enum', 'options' => ['active', 'completed'], 'default' => 'active'],
            ],
            'unique' => [['studentId', 'courseId']],
        ]);
    }

    /** @return list<Descriptor> */
    private function all(): array
    {
        return [
            Descriptor::fromSpec('Student', 'name:string'),
            Descriptor::fromSpec('Course', 'title:string'),
            $this->enrolment(),
        ];
    }

    public function test_a_set_is_written_as_its_fields_and_read_back_the_same(): void
    {
        $enrolment = $this->enrolment();

        $this->assertSame([['studentId', 'courseId']], $enrolment->unique);
        $this->assertSame([['studentId', 'courseId']], $enrolment->toArray()['unique']);
        $this->assertEquals($enrolment, Descriptor::fromJson($enrolment->toJson()));
        $this->assertArrayNotHasKey('unique', Descriptor::fromSpec('Course', 'title:string')->toArray(), 'a resource without one says nothing of it');
    }

    public function test_the_command_names_relations_as_they_are_typed(): void
    {
        $fields = $this->enrolment()->fields;

        $this->assertSame(['studentId', 'courseId'], Descriptor::uniqueSet($fields, 'student, course'));
        $this->assertSame(['studentId', 'status'], Descriptor::uniqueSet($fields, 'studentId,status'));
    }

    public function test_what_makes_no_sense_is_refused(): void
    {
        foreach ([
            [['studentId']],
            [['studentId', 'studentId']],
            [['studentId', 'teacherId']],
            ['studentId'],
        ] as $unique) {
            try {
                Descriptor::fromArray(['unique' => $unique] + $this->enrolment()->toArray());
                $this->fail('accepted '.json_encode($unique));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Enrolment', $e->getMessage());
            }
        }

        $this->expectExceptionMessage('"notes" is a text field');
        Descriptor::fromArray(['name' => 'Note', 'fields' => ['title' => ['kind' => 'string'], 'notes' => ['kind' => 'text']], 'unique' => [['title', 'notes']]]);
    }

    public function test_the_table_the_request_and_a_later_migration_hold_it(): void
    {
        $generator = new ResourceGenerator;
        $enrolment = $this->enrolment();

        $this->assertStringContainsString("            \$table->unique(['student_id', 'course_id']);", $generator->migration($enrolment, $this->all()));
        $this->assertStringContainsString(
            "return [['fields' => ['studentId', 'courseId'], 'table' => 'enrolments', 'message' => 'There is already an enrolment with this student and course.']];",
            $generator->request($enrolment, $this->all()),
        );

        $later = $generator->uniqueMigrationFile($enrolment, ['studentId', 'courseId'], '2026_10_29_120000');
        $this->assertSame('database/migrations/2026_10_29_120000_add_unique_student_id_course_id_to_enrolments_table.php', $later->path);
        $this->assertStringContainsString("havingRaw('count(*) > 1')", $later->contents);
        $this->assertStringContainsString("\$table->dropUnique(['student_id', 'course_id']);", $later->contents);
        $this->assertStringContainsString('Some enrolments have the student and course of another', $later->contents);
        $this->assertStringNotContainsString('together()', $generator->request(Descriptor::fromSpec('Course', 'title:string')), 'a request without one is as it was');
    }

    public function test_a_table_made_without_the_index_is_given_it_once(): void
    {
        $generator = new ResourceGenerator;
        $enrolment = $this->enrolment();
        $index = $generator->uniqueIndex($enrolment, ['studentId', 'courseId']);
        $before = $generator->migration(Descriptor::fromArray(['unique' => []] + $enrolment->toArray()), $this->all());

        $this->assertFalse(GenerateCommand::needsUniqueMigration([], $index), 'a new resource: its own migration has the index');
        $this->assertFalse(GenerateCommand::needsUniqueMigration([$generator->migration($enrolment, $this->all())], $index));
        $this->assertTrue(GenerateCommand::needsUniqueMigration([$before], $index));
        $this->assertTrue(GenerateCommand::needsUniqueMigration([], $index, adopted: true), 'a table that was there before Nevela');
    }
}
