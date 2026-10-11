<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Support\Adoption;
use Nevela\Laravel\Support\Descriptor;
use PHPUnit\Framework\TestCase;

/**
 * A descriptor read from a table that was there first. The tables are the ones of the app
 * the feature was asked for, as MySQL describes them through Laravel's schema builder.
 */
final class AdoptionTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function column(string $name, string $type, bool $nullable = false, mixed $default = null, bool $auto = false): array
    {
        return ['name' => $name, 'type_name' => preg_replace('/[ (].*$/', '', $type), 'type' => $type, 'nullable' => $nullable, 'default' => $default, 'auto_increment' => $auto, 'generation' => null];
    }

    /** @return list<array<string, mixed>> */
    private static function primary(): array
    {
        return [['name' => 'primary', 'columns' => ['id'], 'unique' => true, 'primary' => true]];
    }

    /** @return list<array<string, mixed>> */
    private static function applications(): array
    {
        return [
            self::column('id', 'bigint unsigned', auto: true),
            self::column('name', 'varchar(255)'),
            self::column('email', 'varchar(255)'),
            self::column('phone', 'varchar(255)', nullable: true),
            self::column('track', 'varchar(255)'),
            self::column('cv_path', 'varchar(255)', nullable: true),
            self::column('message', 'text', nullable: true),
            self::column('accepted_at', 'timestamp', nullable: true),
            self::column('created_at', 'timestamp', nullable: true),
            self::column('updated_at', 'timestamp', nullable: true),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function transactions(): array
    {
        return [
            self::column('id', 'bigint unsigned', auto: true),
            self::column('internship_application_id', 'bigint unsigned', nullable: true),
            self::column('reference', 'varchar(255)'),
            self::column('amount', 'bigint unsigned'),
            self::column('status', 'varchar(255)', default: "'pending'"),
            self::column('gateway_response', 'json', nullable: true),
            self::column('created_at', 'timestamp', nullable: true),
            self::column('updated_at', 'timestamp', nullable: true),
        ];
    }

    private function application(): Descriptor
    {
        return Adoption::describe('internship_applications', self::applications(), self::primary(), [])->descriptor;
    }

    public function test_a_table_is_read_into_a_descriptor(): void
    {
        $adoption = Adoption::describe('internship_applications', self::applications(), self::primary(), []);
        $descriptor = $adoption->descriptor;

        $this->assertSame('InternshipApplication', $descriptor->name);
        $this->assertSame('internship_applications', $descriptor->table);
        $this->assertSame('internship-applications', $descriptor->slug);
        $this->assertSame('int', $descriptor->idType);
        $this->assertTrue($descriptor->adopted);
        // id and the timestamps are every resource's already.
        $this->assertSame(['name', 'email', 'phone', 'track', 'cvPath', 'message', 'acceptedAt'], array_keys($descriptor->fields));
        $this->assertSame(['name', 'email', 'phone', 'track', 'cv_path', 'message', 'accepted_at'], array_keys($adoption->fields));
        $this->assertSame([], $adoption->left);

        // Nullable is optional, and each type is the plainest that holds the column:
        // an email is a string until someone who knows the data says otherwise.
        $specs = array_map(Adoption::spec(...), $descriptor->fields);
        $this->assertSame(['name' => 'string', 'email' => 'string', 'phone' => 'string?', 'track' => 'string', 'cvPath' => 'string?', 'message' => 'text?', 'acceptedAt' => 'datetime?'], $specs);

        // What is written is what is read back.
        $this->assertEquals($descriptor, Descriptor::fromJson($descriptor->toJson()));
    }

    public function test_a_foreign_key_to_a_resource_is_a_relation_and_an_unknown_column_is_left_alone(): void
    {
        $indexes = [...self::primary(), ['name' => 'transactions_reference_unique', 'columns' => ['reference'], 'unique' => true, 'primary' => false]];
        $keys = [['name' => 'fk', 'columns' => ['internship_application_id'], 'foreign_table' => 'internship_applications', 'foreign_columns' => ['id'], 'on_delete' => 'set null']];

        $adoption = Adoption::describe('transactions', self::transactions(), $indexes, $keys, [$this->application()]);
        $fields = $adoption->descriptor->fields;

        $this->assertSame('belongsTo', $fields['internshipApplicationId']->kind);
        $this->assertSame('InternshipApplication', $fields['internshipApplicationId']->target);
        $this->assertFalse($fields['internshipApplicationId']->required);
        $this->assertTrue($fields['reference']->unique);
        $this->assertSame('int', $fields['amount']->kind);
        $this->assertSame('string', $fields['status']->kind);
        // The column's default is the field's: a payment made without saying its status is pending.
        $this->assertSame('pending', $fields['status']->default);
        $this->assertNull($fields['reference']->default);
        // There is no field kind for JSON: the column stays in the table, out of the API's way.
        $this->assertArrayNotHasKey('gatewayResponse', $fields);
        $this->assertSame([['column' => 'gateway_response', 'why' => 'json has no field kind']], $adoption->left);
        $this->assertSame([], $adoption->notes);

        // Before its parent is a resource, the same column is a number, and the reason is given.
        $alone = Adoption::describe('transactions', self::transactions(), $indexes, $keys);
        $this->assertSame('int', $alone->descriptor->fields['internshipApplicationId']->kind);
        $this->assertStringContainsString('Adopt internship_applications first', $alone->notes[0]);
    }

    public function test_a_column_keeps_its_own_length_and_its_own_type(): void
    {
        $columns = [
            self::column('id', 'char(36)'),
            self::column('title', 'varchar(255)'),
            self::column('excerpt', 'varchar(500)'),
            self::column('body', 'longtext'),
            self::column('featured', 'tinyint(1)', default: '0'),
            self::column('views', 'int unsigned', default: '0'),
            self::column('rating', 'decimal(3,2)', nullable: true),
            self::column('published_on', 'date', nullable: true),
            self::column('state', "enum('draft','live')"),
            self::column('mood', "enum('so so','fine')"),
            self::column('location', 'point', nullable: true),
            self::column('api_token', 'varchar(80)', nullable: true),
            self::column('checksum', 'binary(16)'),
            self::column('created_at', 'timestamp', nullable: true),
            self::column('updated_at', 'timestamp', nullable: true),
        ];

        $adoption = Adoption::describe('posts', $columns, self::primary(), [], name: 'Article');
        $descriptor = $adoption->descriptor;

        // The name is the one asked for; the table is the one that is there.
        $this->assertSame('Article', $descriptor->name);
        $this->assertSame('posts', $descriptor->table);
        $this->assertSame('articles', $descriptor->slug);
        $this->assertSame('uuid', $descriptor->idType);
        $this->assertSame([
            'title' => 'string',
            'excerpt' => 'string(500)',
            'body' => 'text(4294967295)',
            // A column's own default comes with it: what the field is when a create leaves it out.
            'featured' => 'boolean=false',
            'views' => 'int=0',
            'rating' => 'float?',
            'publishedOn' => 'date?',
            'state' => 'enum(draft|live)',
            // Values an enum field can't have: any text will do.
            'mood' => 'string',
        ], array_map(Adoption::spec(...), $descriptor->fields));

        $this->assertSame(['location' => 'point has no field kind', 'api_token' => 'it looks like a secret', 'checksum' => 'binary has no field kind'], array_column($adoption->left, 'why', 'column'));
        // A column Nevela won't fill, that has to be filled: said now, not found on the first create.
        $this->assertCount(1, $adoption->notes);
        $this->assertStringContainsString('checksum has to have a value and has no default', $adoption->notes[0]);
    }

    public function test_other_databases_describe_the_same_table_in_their_own_words(): void
    {
        // PostgreSQL.
        $postgres = Adoption::describe('posts', [
            ['name' => 'id', 'type_name' => 'int8', 'type' => 'bigint', 'nullable' => false, 'default' => "nextval('posts_id_seq'::regclass)", 'auto_increment' => true, 'generation' => null],
            ['name' => 'title', 'type_name' => 'varchar', 'type' => 'character varying(500)', 'nullable' => false, 'default' => null, 'auto_increment' => false, 'generation' => null],
            ['name' => 'live', 'type_name' => 'bool', 'type' => 'boolean', 'nullable' => false, 'default' => 'false', 'auto_increment' => false, 'generation' => null],
            ['name' => 'meta', 'type_name' => 'jsonb', 'type' => 'jsonb', 'nullable' => true, 'default' => null, 'auto_increment' => false, 'generation' => null],
            ['name' => 'created_at', 'type_name' => 'timestamp', 'type' => 'timestamp(0) without time zone', 'nullable' => true, 'default' => null, 'auto_increment' => false, 'generation' => null],
            ['name' => 'updated_at', 'type_name' => 'timestamp', 'type' => 'timestamp(0) without time zone', 'nullable' => true, 'default' => null, 'auto_increment' => false, 'generation' => null],
        ], [['name' => 'posts_pkey', 'columns' => ['id'], 'unique' => true, 'primary' => true]], []);
        $this->assertSame('int', $postgres->descriptor->idType);
        $this->assertSame(['title' => 'string(500)', 'live' => 'boolean=false'], array_map(Adoption::spec(...), $postgres->descriptor->fields));

        // SQLite, which names no primary index and gives a string no length.
        $sqlite = Adoption::describe('posts', [
            self::column('id', 'integer', auto: true),
            self::column('title', 'varchar'),
            self::column('live', 'tinyint(1)', default: "'0'"),
            self::column('created_at', 'datetime', nullable: true),
            self::column('updated_at', 'datetime', nullable: true),
        ], [], []);
        $this->assertSame('int', $sqlite->descriptor->idType);
        $this->assertSame(['title' => 'string', 'live' => 'boolean=false'], array_map(Adoption::spec(...), $sqlite->descriptor->fields));
    }

    public function test_a_string_that_declares_no_length_is_as_long_as_what_is_in_it(): void
    {
        $columns = [
            self::column('id', 'integer', auto: true),
            self::column('title', 'varchar'),
            self::column('summary', 'varchar'),
            self::column('code', 'varchar(255)'),
            self::column('created_at', 'datetime', nullable: true),
            self::column('updated_at', 'datetime', nullable: true),
        ];

        // Nothing longer than the usual 255 is in the table: the usual rule.
        $short = Adoption::describe('posts', $columns, [], [], longest: ['title' => 40, 'summary' => 255]);
        $this->assertSame(['title' => 'string', 'summary' => 'string', 'code' => 'string'], array_map(Adoption::spec(...), $short->descriptor->fields));
        $this->assertSame([], $short->notes);

        // A value that is there already has to be one the form will save again.
        $long = Adoption::describe('posts', $columns, [], [], longest: ['title' => 40, 'summary' => 900]);
        $this->assertSame(['title' => 'string', 'summary' => 'string(65535)', 'code' => 'string'], array_map(Adoption::spec(...), $long->descriptor->fields));
        $this->assertStringContainsString('summary declares no length and holds values longer than 255', $long->notes[0]);
        $this->assertSame('string(100000)', Adoption::spec(Adoption::describe('posts', $columns, [], [], longest: ['summary' => 100_000])->descriptor->fields['summary']));
    }

    public function test_a_table_with_soft_deletes_of_its_own_is_adopted_with_a_warning_about_the_trash(): void
    {
        $columns = [...self::applications(), self::column('deleted_at', 'timestamp', nullable: true)];
        $adoption = Adoption::describe('internship_applications', $columns, self::primary(), []);

        // The column is the trash's own, not a field, and what the trash does to old rows is said first.
        $this->assertArrayNotHasKey('deletedAt', $adoption->descriptor->fields);
        $this->assertCount(1, $adoption->notes);
        $this->assertStringContainsString('set trash.days to null', $adoption->notes[0]);
        $this->assertSame([], Adoption::describe('internship_applications', self::applications(), self::primary(), [])->notes);
    }

    /** @return iterable<string, array{list<array<string, mixed>>, list<array<string, mixed>>, string}> */
    public static function tablesThatCannotBeResources(): iterable
    {
        $timestamps = [self::column('created_at', 'timestamp', nullable: true), self::column('updated_at', 'timestamp', nullable: true)];

        yield 'no id' => [[self::column('code', 'varchar(10)'), self::column('name', 'varchar(255)'), ...$timestamps], [['name' => 'primary', 'columns' => ['code'], 'unique' => true, 'primary' => true]], 'one column called id'];
        yield 'a key of two columns' => [[self::column('id', 'bigint'), self::column('tenant_id', 'bigint'), ...$timestamps], [['name' => 'primary', 'columns' => ['tenant_id', 'id'], 'unique' => true, 'primary' => true]], 'one column called id'];
        yield 'ids of another kind' => [[self::column('id', 'char(26)'), self::column('name', 'varchar(255)'), ...$timestamps], self::primary(), 'neither integers nor UUIDs'];
        yield 'no timestamps' => [[self::column('id', 'bigint', auto: true), self::column('name', 'varchar(255)')], self::primary(), 'has no created_at or updated_at'];
        yield 'one timestamp' => [[self::column('id', 'bigint', auto: true), self::column('name', 'varchar(255)'), self::column('created_at', 'timestamp')], self::primary(), 'has no updated_at.'];
        yield 'nothing to show' => [[self::column('id', 'bigint', auto: true), self::column('payload', 'json'), ...$timestamps], self::primary(), 'no column that can be a field'];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $indexes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tablesThatCannotBeResources')]
    public function test_a_table_that_cannot_be_a_resource_is_refused_with_the_reason(array $columns, array $indexes, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);
        Adoption::describe('things', $columns, $indexes, []);
    }
}
