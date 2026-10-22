<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Generator\Writer;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Removal;
use PHPUnit\Framework\TestCase;

/**
 * What `nevela resource:remove` would take out: only what is still as Nevela wrote it.
 * The files are generated into a folder of their own, as `nevela resource` would write them.
 */
final class RemovalTest extends TestCase
{
    private string $dir;

    /** @var array<string, string> */
    private array $roots;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nevela-remove-'.bin2hex(random_bytes(4));
        $this->roots = ['api' => $this->dir.DIRECTORY_SEPARATOR.'api', 'web' => $this->dir.DIRECTORY_SEPARATOR.'web'];
        mkdir($this->dir.'/descriptors', 0775, true);
    }

    private function gadget(bool $adopted = false): Descriptor
    {
        $gadget = Descriptor::fromSpec('Gadget', 'name:string, price:money?');
        if (! $adopted) {
            return $gadget;
        }
        $data = json_decode($gadget->toJson(), true);
        $data['adopted'] = true;

        return Descriptor::fromJson((string) json_encode($data));
    }

    /** @param list<Descriptor> $all */
    private function written(Descriptor $resource, array $all): void
    {
        file_put_contents($this->dir.'/descriptors/gadget.json', $resource->toJson());
        (new Writer($this->roots))->write((new ResourceGenerator)->forResource($resource, '2026_10_22_000000', $all));
    }

    private function plan(Descriptor $resource, array $all): Removal
    {
        return Removal::plan($resource, $all, $this->roots, $this->path('descriptors/gadget.json'), new ResourceGenerator);
    }

    private function path(string $relative): string
    {
        return str_replace('/', DIRECTORY_SEPARATOR, $this->dir.'/'.$relative);
    }

    public function test_what_nevela_wrote_and_nobody_touched_is_all_deleted_and_the_migration_kept(): void
    {
        $gadget = $this->gadget();
        $this->written($gadget, [$gadget]);
        $plan = $this->plan($gadget, [$gadget]);

        $this->assertSame([], $plan->paths(Removal::YOURS));
        $deleted = $plan->paths(Removal::DELETE);
        foreach (['descriptors/gadget.json', 'api/app/Models/Gadget.php', 'api/app/Policies/GadgetPolicy.php', 'web/resources/gadget.resource.ts', 'web/app/dashboard/gadgets/[id]/edit/page.tsx'] as $file) {
            $this->assertContains($this->path($file), $deleted, $file);
        }
        // The table's history stays: the data is never touched here.
        $this->assertSame([$this->path('api/database/migrations/2026_10_22_000000_create_gadgets_table.php')], $plan->paths(Removal::MIGRATION));
        // So do the migrations added since: the trash, and a public form's confirmation.
        $confirm = (new ResourceGenerator)->confirmMigrationFile($gadget, '2026_10_22_000001');
        $trash = (new ResourceGenerator)->trashMigrationFile($gadget, '2026_10_22_000002');
        (new Writer($this->roots))->write([$confirm, $trash]);
        $migrations = $this->plan($gadget, [$gadget])->paths(Removal::MIGRATION);
        $this->assertContains($this->path('api/'.$confirm->path), $migrations);
        $this->assertContains($this->path('api/'.$trash->path), $migrations);
        $this->assertSame([], $plan->blockers);
    }

    public function test_a_file_changed_since_is_left_for_you(): void
    {
        $gadget = $this->gadget();
        $this->written($gadget, [$gadget]);
        // A page made the app's own, a generated block edited inside, code added beside a block.
        file_put_contents($this->path('web/app/dashboard/gadgets/page.tsx'), "// mine now\n", FILE_APPEND);
        $request = $this->path('api/app/Http/Requests/Nevela/GadgetRequest.php');
        file_put_contents($request, str_replace("'name' =>", "'name'  =>", (string) file_get_contents($request)));
        $model = $this->path('api/app/Models/Gadget.php');
        file_put_contents($model, preg_replace('/\}\s*$/', "    public function shine(): string\n    {\n        return 'shiny';\n    }\n}\n", (string) file_get_contents($model)));

        $yours = $this->plan($gadget, [$gadget])->paths(Removal::YOURS);
        $this->assertContains($this->path('web/app/dashboard/gadgets/page.tsx'), $yours);
        $this->assertContains($request, $yours);
        $this->assertContains($model, $yours);
    }

    public function test_what_an_older_nevela_wrote_is_still_nevelas(): void
    {
        $gadget = $this->gadget();
        $this->written($gadget, [$gadget]);
        // A comment worded differently since, and a skeleton made when there were fewer fields.
        $model = $this->path('api/app/Models/Gadget.php');
        file_put_contents($model, preg_replace('#^(\s*)// .*outside the generated block.*$#m', '$1// An older wording of the same note.', (string) file_get_contents($model)));
        $loading = $this->path('web/app/dashboard/gadgets/new/loading.tsx');
        file_put_contents($loading, preg_replace('/fields=\{\d+\}/', 'fields={1}', (string) file_get_contents($loading)));

        $deleted = $this->plan($gadget, [$gadget])->paths(Removal::DELETE);
        $this->assertContains($model, $deleted);
        $this->assertContains($loading, $deleted);

        // Any other number in a skeleton is the app's choice, and keeps it the app's.
        file_put_contents($loading, str_replace('actions={0}', 'actions={2}', (string) file_get_contents($loading)));
        $edit = $this->path('web/app/dashboard/gadgets/[id]/loading.tsx');
        file_put_contents($edit, str_replace('<PageHeaderSkeleton />', '<PageHeaderSkeleton actions={3} />', (string) file_get_contents($edit)));
        $yours = $this->plan($gadget, [$gadget])->paths(Removal::YOURS);
        $this->assertContains($loading, $yours);
        $this->assertContains($edit, $yours);
    }

    public function test_an_adopted_model_keeps_its_own_code_and_loses_only_nevelas_block(): void
    {
        $gadget = $this->gadget(adopted: true);
        $this->written($gadget, [$gadget]);
        $model = $this->path('api/app/Models/Gadget.php');
        // The model it had before Nevela, with Nevela's block put in it.
        file_put_contents($model, preg_replace('/\}\s*$/', "    public function legacy(): bool\n    {\n        return true;\n    }\n}\n", (string) file_get_contents($model)));

        $plan = $this->plan($gadget, [$gadget]);
        $this->assertSame([$model], $plan->paths(Removal::UNBLOCK));
        $this->assertSame([], $plan->paths(Removal::MIGRATION), 'an adopted table has no migration of Nevela\'s');

        $after = Removal::withoutBlock((string) file_get_contents($model));
        $this->assertStringNotContainsString(Writer::START, $after);
        $this->assertStringContainsString('function legacy()', $after);
        $this->assertNull(Writer::block($after));
    }

    public function test_one_that_another_resource_belongs_to_is_refused(): void
    {
        $gadget = $this->gadget();
        $order = Descriptor::fromSpec('Order', 'total:money, gadget:belongsTo(Gadget)');
        $this->written($gadget, [$gadget, $order]);

        $this->assertSame(['Order.gadgetId belongs to Gadget. Take that field out of order.json first, or remove Order.'], $this->plan($gadget, [$gadget, $order])->blockers);
    }

    public function test_the_apps_own_code_that_still_names_it_is_pointed_out(): void
    {
        $gadget = $this->gadget();
        $shelf = Descriptor::fromSpec('Shelf', 'name:string');
        $holder = Descriptor::fromSpec('Holder', 'name:string, gadget:belongsTo(Gadget)');
        $this->written($gadget, [$gadget, $shelf]);
        mkdir($this->path('api/app/Http/Controllers/Shop'), 0775, true);
        file_put_contents($this->path('api/app/Http/Controllers/Shop/WindowController.php'), "<?php\nuse App\\Models\\Gadget;\nGadget::query()->first();\n");
        file_put_contents($this->path('web/app/dashboard/home.tsx'), "import gadgetResource from \"@/resources/gadget.resource\";\n");
        // A generated hasMany on another model is regenerated without it: not the app's to fix.
        (new Writer($this->roots))->write((new ResourceGenerator)->forResource($holder, '2026_10_22_000001', [$gadget, $holder]));
        (new Writer($this->roots))->write([new \Nevela\Laravel\Generator\GeneratedFile('api', 'app/Models/Shelf.php', (new ResourceGenerator)->model($shelf, [$shelf]))]);

        // What reads its public section, as the blog's lib/blog.ts reads posts, and a form that posts to it.
        mkdir($this->path('web/lib'), 0775, true);
        file_put_contents($this->path('web/lib/catalogue.ts'), "const { body } = await laravelPublic(`gadgets?sort=-createdAt`, { tags: [resourceTag(\"Gadget\")] });\n");
        mkdir($this->path('web/components'), 0775, true);
        file_put_contents($this->path('web/components/order-form.tsx'), "await fetch(\"/api/public/gadgets\", { method: \"POST\" });\n");
        // An example in a comment is not code that breaks: lib/laravel.ts shows one for "posts".
        file_put_contents($this->path('web/lib/laravel.ts'), "/**\n * const { body } = await laravelPublic(\"gadgets?sort=-publishedAt\");\n */\nexport const API_URL = \"\";\n");
        // A block comment's lines needn't start with a star, and a line comment can follow code.
        file_put_contents($this->path('web/lib/notes.ts'), "/*\nlaravelPublic(\"gadgets\");\n*/\n// fetch(\"/api/public/gadgets\")\nexport const notes = 1;\n");

        $mentions = $this->plan($gadget, [$gadget, $shelf])->mentions;
        $this->assertContains($this->path('api/app/Http/Controllers/Shop/WindowController.php'), $mentions);
        $this->assertContains($this->path('web/app/dashboard/home.tsx'), $mentions);
        $this->assertContains($this->path('web/lib/catalogue.ts'), $mentions);
        $this->assertContains($this->path('web/components/order-form.tsx'), $mentions);
        $this->assertNotContains($this->path('web/lib/laravel.ts'), $mentions);
        $this->assertNotContains($this->path('web/lib/notes.ts'), $mentions);
        $this->assertNotContains($this->path('api/app/Models/Gadget.php'), $mentions, 'its own files are going anyway');
        $this->assertNotContains($this->path('api/app/Models/Shelf.php'), $mentions);
    }
}
