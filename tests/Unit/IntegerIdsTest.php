<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Console\GenerateCommand;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Generator\Writer;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use PHPUnit\Framework\TestCase;

/**
 * A resource whose ids are the integers of `$table->id()`, beside the UUIDs Nevela makes
 * by default, and a string field with a length of its own.
 */
final class IntegerIdsTest extends TestCase
{
    private function author(string $idType = 'int'): Descriptor
    {
        return Descriptor::fromSpec('Author', 'name:string', idType: $idType);
    }

    private function article(string $idType = 'int'): Descriptor
    {
        return Descriptor::fromSpec('Article', 'title:string, author:belongsTo(Author), editor:belongsTo(Author)?', idType: $idType);
    }

    public function test_the_id_type_is_in_the_descriptor_only_when_it_is_not_a_uuid(): void
    {
        $this->assertSame('int', Descriptor::fromJson($this->author()->toJson())->idType);
        $this->assertSame('int', $this->author()->toArray()['id']);

        // A descriptor from before there was a choice says nothing, and means UUIDs.
        $uuid = $this->author('uuid');
        $this->assertArrayNotHasKey('id', $uuid->toArray());
        $this->assertArrayNotHasKey('adopted', $uuid->toArray());
        $this->assertSame('uuid', Descriptor::fromJson($uuid->toJson())->idType);
        $this->assertFalse(Descriptor::fromJson($uuid->toJson())->adopted);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Use uuid or int');
        Descriptor::fromSpec('Author', 'name:string', idType: 'ulid');
    }

    public function test_the_model_and_the_table_are_numbered_by_the_database(): void
    {
        $generator = new ResourceGenerator;

        $model = $generator->model($this->author());
        $this->assertStringNotContainsString('HasUuids', $model);
        $this->assertStringContainsString("use Illuminate\\Database\\Eloquent\\Model;\n\nclass Author extends Model\n{\n    protected \$table = 'authors';\n", $model);

        $migration = $generator->migration($this->author());
        $this->assertStringContainsString("\$table->id();\n", $migration);
        $this->assertStringNotContainsString('uuid', $migration);

        // With UUIDs, both are what they always were.
        $this->assertStringContainsString("class Author extends Model\n{\n    use HasUuids;\n\n    protected \$table = 'authors';\n", $generator->model($this->author('uuid')));
        $this->assertStringContainsString("\$table->uuid('id')->primary();\n", $generator->migration($this->author('uuid')));
    }

    public function test_a_relation_follows_the_ids_of_the_resource_it_points_at(): void
    {
        $generator = new ResourceGenerator;

        // Integer to integer.
        $all = [$this->author(), $this->article()];
        $migration = $generator->migration($this->article(), $all);
        $this->assertStringContainsString("\$table->foreignId('author_id')->index()->constrained('authors');", $migration);
        $this->assertStringContainsString("\$table->foreignId('editor_id')->nullable()->index()->constrained('authors')->nullOnDelete();", $migration);
        $this->assertStringContainsString("'authorId' => ['required', 'integer', 'digits_between:1,18', Rule::exists('authors', 'id')],", $generator->request($this->article(), $all));

        // A UUID resource that belongs to an integer one: its own id is a UUID, the column is not.
        $all = [$this->author(), $this->article('uuid')];
        $migration = $generator->migration($this->article('uuid'), $all);
        $this->assertStringContainsString("\$table->uuid('id')->primary();", $migration);
        $this->assertStringContainsString("\$table->foreignId('author_id')", $migration);
        $this->assertStringContainsString("'authorId' => ['required', 'integer',", $generator->request($this->article('uuid'), $all));

        // And the reverse.
        $all = [$this->author('uuid'), $this->article()];
        $migration = $generator->migration($this->article(), $all);
        $this->assertStringContainsString('$table->id();', $migration);
        $this->assertStringContainsString("\$table->foreignUuid('author_id')", $migration);
        $this->assertStringContainsString("'authorId' => ['required', 'uuid', Rule::exists('authors', 'id')],", $generator->request($this->article(), $all));
    }

    public function test_an_id_is_a_string_in_the_api(): void
    {
        $generator = new ResourceGenerator;
        $all = [$this->author(), $this->article()];

        $resource = $generator->resource($this->article(), $all);
        $this->assertStringContainsString("'id' => (string) \$this->id,", $resource);
        $this->assertStringContainsString("'authorId' => \$this->author_id === null ? null : (string) \$this->author_id,", $resource);
        $this->assertStringContainsString("'editorId' => \$this->editor_id === null ? null : (string) \$this->editor_id,", $resource);

        // A UUID is one already, and its resource is written as it always was.
        $all = [$this->author('uuid'), $this->article('uuid')];
        $resource = $generator->resource($this->article('uuid'), $all);
        $this->assertStringContainsString("'id' => \$this->id,", $resource);
        $this->assertStringContainsString("'authorId' => \$this->author_id,", $resource);
        $this->assertStringNotContainsString('(string)', $resource);
    }

    public function test_only_digits_are_an_integer_resources_address(): void
    {
        $routes = (new ResourceGenerator)->routes([$this->author(), $this->article('uuid')])->contents;

        $this->assertStringContainsString("Route::apiResource('authors', \\App\\Http\\Controllers\\Api\\AuthorController::class)->where(['author' => '[0-9]{1,18}']);", $routes);
        $this->assertStringContainsString("Route::apiResource('articles', \\App\\Http\\Controllers\\Api\\ArticleController::class);", $routes);
    }

    public function test_the_generated_php_is_valid(): void
    {
        $dir = sys_get_temp_dir().'/nevela-int-'.bin2hex(random_bytes(4));
        $generator = new ResourceGenerator;
        $all = [$this->author(), $this->article()];
        $files = [...$generator->forResource($this->author(), '2026_10_02_000000', $all), ...$generator->forResource($this->article(), '2026_10_02_000001', $all), $generator->routes($all)];
        $report = (new Writer(['api' => "{$dir}/api", 'web' => "{$dir}/web"]))->write($files);

        foreach (array_column($report, 'path') as $path) {
            if (str_ends_with($path, '.php')) {
                exec('php -l '.escapeshellarg($path).' 2>&1', $out, $code);
                $this->assertSame(0, $code, implode("\n", $out));
            }
        }
    }

    public function test_an_adopted_table_gets_no_migration_to_create_it(): void
    {
        $adopted = Descriptor::fromArray(['name' => 'Author', 'id' => 'int', 'adopted' => true, 'fields' => ['name' => ['kind' => 'string']]]);
        $this->assertTrue($adopted->adopted);
        $this->assertTrue(Descriptor::fromJson($adopted->toJson())->adopted);

        $paths = fn (Descriptor $d) => array_map(fn ($file) => $file->path, (new ResourceGenerator)->forResource($d, '2026_10_02_000000'));
        $this->assertContains('database/migrations/2026_10_02_000000_create_authors_table.php', $paths($this->author()));
        $this->assertNotContains('database/migrations/2026_10_02_000000_create_authors_table.php', $paths($adopted));
        $this->assertContains('app/Models/Author.php', $paths($adopted));

        // It gets the one that gives it a trash, whether or not a migration of that name made the table,
        $this->assertTrue(GenerateCommand::needsTrashMigration([], false, false, adopted: true));
        $this->assertTrue(GenerateCommand::needsTrashMigration([], true, true, adopted: true));
        // unless the column is the app's own.
        $this->assertFalse(GenerateCommand::needsTrashMigration([], false, true, adopted: true));
        $this->assertFalse(GenerateCommand::needsTrashMigration(['$table->softDeletes();'], false, false, adopted: true));
    }

    public function test_a_string_can_have_a_length_of_its_own(): void
    {
        $post = Descriptor::fromSpec('Post', 'title:string, excerpt:string(500)?, body:text(100000), site:url(300), price:money(2)');
        $this->assertSame(500, $post->fields['excerpt']->maxLength);
        $this->assertNull($post->fields['title']->maxLength);
        // A number means a length only where there is one to set.
        $this->assertNull($post->fields['price']->maxLength);
        $this->assertSame(500, Descriptor::fromJson($post->toJson())->fields['excerpt']->maxLength);
        $this->assertArrayNotHasKey('maxLength', $post->toArray()['fields']['title']);

        $generator = new ResourceGenerator;
        $request = $generator->request($post);
        $this->assertStringContainsString("'title' => ['required', 'string', 'max:255'],", $request);
        $this->assertStringContainsString("'excerpt' => ['nullable', 'string', 'max:500'],", $request);
        $this->assertStringContainsString("'body' => ['required', 'string', 'max:100000'],", $request);
        $this->assertStringContainsString("'site' => ['required', 'url', 'max:300'],", $request);

        $migration = $generator->migration($post);
        $this->assertStringContainsString("\$table->string('title');", $migration);
        $this->assertStringContainsString("\$table->string('excerpt', 500)->nullable();", $migration);
        $this->assertStringContainsString("\$table->longText('body');", $migration);
        $this->assertStringContainsString("\$table->string('site', 300);", $migration);

        // The dashboard checks the same length before it sends anything.
        $typescript = $generator->typescript($post);
        $this->assertStringContainsString('title: field.string(),', $typescript);
        $this->assertStringContainsString('excerpt: field.string({ required: false, maxLength: 500, list: true, ...({ column: "hidden" } as {}) }),', $typescript);
        $this->assertStringContainsString('body: field.text({ maxLength: 100000, list: true, ...({ column: "hidden" } as {}) }),', $typescript);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("can't have a length");
        new Field('price', 'float', 'money', maxLength: 10);
    }
}
