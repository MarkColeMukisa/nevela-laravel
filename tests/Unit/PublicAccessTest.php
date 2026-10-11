<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The "public" section of a descriptor, and a field's default, which a public create leans on. */
final class PublicAccessTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private const FIELDS = [
        'title' => ['kind' => 'string'],
        'slug' => ['kind' => 'string', 'format' => 'slug', 'unique' => true],
        'body' => ['kind' => 'text', 'required' => false],
        'status' => ['kind' => 'enum', 'options' => ['draft', 'live'], 'default' => 'draft'],
        'pinned' => ['kind' => 'boolean'],
        'scan' => ['kind' => 'file', 'accept' => ['pdf'], 'private' => true, 'required' => false],
        'cover' => ['kind' => 'file', 'accept' => ['image'], 'required' => false],
    ];

    /** @param array<string, mixed>|null $public */
    private function post(?array $public): Descriptor
    {
        return Descriptor::fromArray(array_filter(['name' => 'Post', 'fields' => self::FIELDS, 'public' => $public]));
    }

    public function test_the_section_is_read_and_written_back_as_it_was(): void
    {
        $public = [
            'read' => ['by' => 'slug', 'fields' => ['title', 'slug', 'body'], 'throttle' => '120,1'],
            'create' => ['fields' => ['title', 'slug', 'scan']],
            'middleware' => ['honeypot'],
        ];
        $post = $this->post($public);

        $this->assertSame($public, $post->toArray()['public']);
        $this->assertEquals($post, Descriptor::fromJson($post->toJson()));
        $this->assertSame(['title', 'slug', 'body'], $post->public->read);
        $this->assertSame('10,1', $post->public->createThrottle);

        // With no section nothing is open, and the descriptor says nothing of it.
        $this->assertNull($this->post(null)->public);
        $this->assertArrayNotHasKey('public', $this->post(null)->toArray());
        // Reading alone opens no way to write.
        $this->assertNull($this->post(['read' => ['fields' => ['title']]])->public->create);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function mistakes(): iterable
    {
        yield 'opening nothing' => [['middleware' => ['honeypot']], 'give it "read", "create" or both'];
        yield 'no fields to read' => [['read' => ['fields' => []]], 'names no fields'];
        yield 'a field it does not have' => [['read' => ['fields' => ['title', 'author']]], '"author", which is not one of its fields'];
        yield 'a private file, to anyone' => [['read' => ['fields' => ['title', 'scan']]], 'is a private file'];
        yield 'fetched by a field that is not unique' => [['read' => ['by' => 'title', 'fields' => ['title']]], 'has to be a unique field'];
        yield 'a required field the form can not send' => [['create' => ['fields' => ['title']]], '"slug" is required and a public create can\'t send it'];
        yield 'a throttle that is not one' => [['read' => ['fields' => ['title'], 'throttle' => 'often']], 'is not a throttle'];
        yield 'middleware that is not a name' => [['read' => ['fields' => ['title']], 'middleware' => ['x; y']], 'named as in a route'];
    }

    /** @param array<string, mixed> $public */
    #[DataProvider('mistakes')]
    public function test_a_mistake_in_the_section_is_refused_with_what_is_wrong(array $public, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->post($public);
    }

    public function test_a_create_may_leave_out_what_has_a_default_or_is_optional_or_is_a_yes_or_no(): void
    {
        // status has a default, pinned is no unless said, body, scan and cover are optional.
        $post = $this->post(['create' => ['fields' => ['title', 'slug']]]);

        $this->assertSame(['title', 'slug'], $post->public->create);
    }

    public function test_only_what_is_opened_gets_a_route_and_each_its_own_count(): void
    {
        $generator = new ResourceGenerator;
        $post = $this->post(['read' => ['by' => 'slug', 'fields' => ['title', 'slug']], 'create' => ['fields' => ['title', 'slug', 'scan'], 'throttle' => '3,1'], 'middleware' => ['honeypot']]);
        $plain = Descriptor::fromSpec('Category', 'name:string');

        $file = $generator->publicRoutes([$plain, $post]);
        $this->assertSame('routes/nevela-public.php', $file->path);
        $routes = $file->contents;
        $controller = '[\\Nevela\\Laravel\\Http\\PublicController::class';
        $this->assertStringContainsString("Route::get('posts', {$controller}, 'index'])->defaults('resource', 'Post')->middleware(['nevela.throttle:60,1,posts.read:', 'honeypot'])->name('posts.index');", $routes);
        $this->assertStringContainsString("Route::get('posts/{id}', {$controller}, 'show'])", $routes);
        $this->assertStringContainsString("Route::post('posts', {$controller}, 'store'])->defaults('resource', 'Post')->middleware(['nevela.throttle:3,1,posts.create:', 'honeypot'])->name('posts.store');", $routes);
        // An upload route for the file field the form lists, and for no other.
        $this->assertStringContainsString("Route::put('_nevela/uploads/Post/scan', {$controller}, 'upload'])->defaults('resource', 'Post')->defaults('field', 'scan')->middleware(['nevela.throttle:3,1,posts.upload:', 'honeypot'])", $routes);
        $this->assertStringNotContainsString('uploads/Post/cover', $routes);
        $this->assertStringNotContainsString('categories', $routes);

        // Reading alone: no way to write.
        $readOnly = $generator->publicRoutes([$this->post(['read' => ['fields' => ['title']]])])->contents;
        $this->assertStringNotContainsString('Route::post', $readOnly);
        $this->assertStringNotContainsString('Route::put', $readOnly);

        // No section anywhere: the file is there, and empty of routes.
        $none = $generator->publicRoutes([$plain])->contents;
        $this->assertStringNotContainsString('Route::get', $none);
        $this->assertStringContainsString('nothing is open', $none);

        foreach ([$routes, $none] as $php) {
            $path = sys_get_temp_dir().'/nevela-public-'.bin2hex(random_bytes(4)).'.php';
            file_put_contents($path, $php);
            exec('php -l '.escapeshellarg($path).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }
    }

    public function test_the_model_is_written_with_a_scope_that_lets_nothing_through(): void
    {
        $generator = new ResourceGenerator;

        $model = $generator->model($this->post(['read' => ['fields' => ['title']]]));
        $this->assertStringContainsString('public function scopePublicQuery(\Illuminate\Database\Eloquent\Builder $query): void', $model);
        $this->assertStringContainsString("\$query->whereRaw('1 = 0');", $model);
        // Outside the generated block: it is the app's to fill in, and regenerating leaves it alone.
        $this->assertGreaterThan(strpos($model, 'nevela:generated:end'), strpos($model, 'scopePublicQuery'));

        // A resource nobody reads publicly has the model it always had.
        $this->assertStringNotContainsString('scopePublicQuery', $generator->model($this->post(null)));
        $this->assertStringNotContainsString('scopePublicQuery', $generator->model($this->post(['create' => ['fields' => ['title', 'slug']]])));
    }

    public function test_a_field_can_say_what_it_is_when_a_create_leaves_it_out(): void
    {
        $order = Descriptor::fromSpec('Order', 'reference:string!, status:enum(pending|paid)=pending, quantity:int=1, rate:float=2.5, gift:boolean=true, note:string?=none, total:money');
        $this->assertSame(['pending', 1, 2.5, true, 'none', null], array_map(fn (string $name) => $order->fields[$name]->default, ['status', 'quantity', 'rate', 'gift', 'note', 'total']));
        $this->assertSame('pending', Descriptor::fromJson($order->toJson())->fields['status']->default);
        $this->assertArrayNotHasKey('default', $order->toArray()['fields']['total']);

        $generator = new ResourceGenerator;
        // Left out, it is not asked for; sent, it is checked as ever.
        $request = $generator->request($order);
        $this->assertStringContainsString("'status' => ['sometimes', 'required', Rule::in(['pending', 'paid'])],", $request);
        $this->assertStringContainsString("'total' => ['required', 'numeric'],", $request);
        $this->assertStringContainsString("protected function defaults(): array\n    {\n        return ['status' => 'pending', 'quantity' => 1, 'rate' => 2.5, 'gift' => true, 'note' => 'none'];\n    }", $request);
        // The column has it too, for a row made some other way.
        $migration = $generator->migration($order);
        $this->assertStringContainsString("\$table->string('status')->default('pending')->index();", $migration);
        $this->assertStringContainsString("\$table->integer('quantity')->default(1);", $migration);
        $this->assertStringContainsString("\$table->boolean('gift')->default(true);", $migration);
        // And the dashboard's form starts with it.
        $typescript = $generator->typescript($order);
        $this->assertStringContainsString('status: field.enum(["pending","paid"], { default: "pending" }),', $typescript);
        $this->assertStringContainsString('gift: field.boolean({ default: true }),', $typescript);

        // A resource with none has the request it always had.
        $this->assertStringNotContainsString('defaults()', $generator->request(Descriptor::fromSpec('Note', 'body:text')));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badDefaults(): iterable
    {
        yield 'not one of the choices' => [['kind' => 'enum', 'options' => ['a', 'b'], 'default' => 'c'], 'one of a, b'];
        yield 'words for a number' => [['kind' => 'int', 'default' => 'many'], 'a value the field could hold'];
        yield 'a number for a yes or no' => [['kind' => 'boolean', 'default' => 1], 'a value the field could hold'];
        yield 'for a date' => [['kind' => 'date', 'default' => '2026-01-01'], "a date field can't have one"];
        yield 'for a file' => [['kind' => 'file', 'accept' => ['pdf'], 'default' => 'x'], "a file field can't have one"];
        yield 'for a unique field' => [['kind' => 'string', 'unique' => true, 'default' => 'x'], 'only one record could ever have its default'];
    }

    /** @param array<string, mixed> $field */
    #[DataProvider('badDefaults')]
    public function test_a_default_the_field_could_not_hold_is_refused(array $field, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        Field::fromArray('thing', $field);
    }
}
