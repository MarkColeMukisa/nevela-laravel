<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Generator\Writer;
use Nevela\Laravel\Support\Descriptor;
use PHPUnit\Framework\TestCase;

final class GeneratorTest extends TestCase
{
    private function product(): Descriptor
    {
        return Descriptor::fromSpec('Product', 'name:string, sku:string!, description:text?, price:money, stock:int?, active:boolean, kind:enum(stock|digital), launchOn:date?, website:url?', 'package', 'Catalogue');
    }

    public function test_generates_valid_php_for_every_laravel_file(): void
    {
        $dir = sys_get_temp_dir().'/nevela-gen-'.bin2hex(random_bytes(4));
        $generator = new ResourceGenerator;
        $files = [...$generator->forResource($this->product(), '2026_10_02_000000'), $generator->routes([$this->product()]), $generator->registry([$this->product()]), $generator->trashMigrationFile($this->product(), '2026_10_03_000000')];
        $report = (new Writer(['api' => "{$dir}/api", 'web' => "{$dir}/web"]))->write($files);

        $this->assertSame(array_fill(0, 18, 'created'), array_column($report, 'status'));
        foreach (array_column($report, 'path') as $path) {
            if (str_ends_with($path, '.php')) {
                exec('php -l '.escapeshellarg($path).' 2>&1', $out, $code);
                $this->assertSame(0, $code, implode("\n", $out));
            }
        }
    }

    public function test_request_rules_follow_the_descriptor(): void
    {
        $php = (new ResourceGenerator)->request($this->product());

        $this->assertStringContainsString("'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')->ignore(\$product)],", $php);
        $this->assertStringContainsString("'description' => ['nullable', 'string', 'max:65535'],", $php);
        $this->assertStringContainsString("'kind' => ['required', Rule::in(['stock', 'digital'])],", $php);
        $this->assertStringContainsString("'launchOn' => ['nullable', 'date_format:Y-m-d'],", $php);
        $this->assertStringContainsString("'launchOn' => 'launch_on'", $php);
    }

    public function test_a_table_gets_the_trash_migration_only_when_it_needs_one(): void
    {
        $plain = "Schema::create('products', function (Blueprint \$table) { \$table->uuid('id'); });";
        $withTrash = "Schema::create('products', function (Blueprint \$table) { \$table->softDeletes(); });";
        $needs = \Nevela\Laravel\Console\GenerateCommand::needsTrashMigration(...);

        // A table from before the trash, with no column yet.
        $this->assertTrue($needs([$plain], false, false));
        // Written already: it stays in the list, where it is reported as yours and left alone.
        $this->assertTrue($needs([$plain], true, true));
        // A new resource: its own migration has the column.
        $this->assertFalse($needs([$withTrash], false, false));
        $this->assertFalse($needs([], false, false));
        // The column is there by a migration of the app's own. One of ours would drop it on rollback.
        $this->assertFalse($needs([$plain], false, true));
    }

    public function test_a_resource_has_a_trash(): void
    {
        $generator = new ResourceGenerator;

        // The model keeps deleted records, from inside the generated block so an existing model gains it.
        $model = $generator->model($this->product());
        $this->assertGreaterThan(strpos($model, 'nevela:generated:start'), strpos($model, 'use \\Nevela\\Laravel\\Concerns\\Trashable;'));
        // A new table has the column; an older one gets a migration that adds it, once.
        $this->assertStringContainsString('$table->softDeletes();', $generator->migration($this->product()));
        $added = $generator->trashMigrationFile($this->product(), '2026_10_03_000000');
        $this->assertSame('database/migrations/2026_10_03_000000_add_trash_to_products_table.php', $added->path);
        $this->assertSame('database/migrations/*_add_trash_to_products_table.php', $added->existsGlob);
        $this->assertStringContainsString("Schema::hasColumn('products', 'deleted_at')", $added->contents);
        // And the policy says who may restore and who may remove for good.
        $policy = $generator->policy($this->product());
        $this->assertStringContainsString('public function restore(User $user, Product $product): bool', $policy);
        $this->assertStringContainsString('public function forceDelete(User $user, Product $product): bool', $policy);
    }

    public function test_a_policy_asks_for_the_resources_permissions(): void
    {
        $php = (new ResourceGenerator)->policy($this->product());

        // One permission per action, named after the table, and nothing allowed outright.
        // (viewAny and view share one; "edit" is also in the comment's example; whoever may
        // delete may also restore from the trash and remove from it for good.)
        foreach (["'products.view'" => 2, "'products.create'" => 1, "'products.edit'" => 2, "'products.delete'" => 3] as $permission => $times) {
            $this->assertSame($times, substr_count($php, "\$user->can({$permission})"), $permission);
        }
        $this->assertStringNotContainsString('return true;', $php);
    }

    public function test_emits_a_flare_descriptor(): void
    {
        $ts = (new ResourceGenerator)->typescript($this->product());

        $this->assertStringContainsString('import { defineResource, field } from "@flaredev/core";', $ts);
        $this->assertStringContainsString('    sku: field.string({ unique: true }),', $ts);
        $this->assertStringContainsString('    price: field.float({ format: "money" }),', $ts);
        $this->assertStringContainsString('    website: field.url({ required: false }),', $ts);
        $this->assertStringContainsString('    kind: field.enum(["stock","digital"]),', $ts);
        $this->assertStringContainsString('  group: "Catalogue",', $ts);
    }

    public function test_emits_the_web_registry_and_dashboard_pages(): void
    {
        $generator = new ResourceGenerator;
        $item = Descriptor::fromSpec('OrderItem', 'label:string');

        $registry = $generator->registry([$this->product(), $item])->contents;
        $this->assertStringContainsString('import orderItemResource from "./order-item.resource";', $registry);
        $this->assertStringContainsString('export const resources = [orderItemResource, productResource] as const;', $registry);

        // A new app has no resources: the registry is empty, not missing.
        $empty = $generator->registry([])->contents;
        $this->assertStringContainsString("export const resources = [] as const;\n", $empty);
        $this->assertStringNotContainsString('import ', $empty);
        $this->assertStringNotContainsString('export {', $empty);

        $pages = [];
        foreach ($generator->pages($item) as $file) {
            $pages[$file->path] = $file;
        }
        $dir = "app/dashboard/{$item->slug}/";
        $this->assertSame(
            ['page.tsx', 'loading.tsx', 'new/page.tsx', 'new/loading.tsx', '[id]/page.tsx', '[id]/loading.tsx', '[id]/edit/page.tsx', '[id]/edit/loading.tsx'],
            array_map(fn ($path) => substr($path, strlen($dir)), array_keys($pages)),
        );
        $this->assertSame('once', $pages[$dir.'page.tsx']->mode);
        $this->assertStringContainsString('import orderItemResource from "@/resources/order-item.resource";', $pages[$dir.'page.tsx']->contents);
        $this->assertStringContainsString('<ResourceTable resource={orderItemResource} searchParams={await searchParams} />', $pages[$dir.'page.tsx']->contents);
    }

    public function test_the_root_launcher_is_valid_php_and_points_at_the_laravel_app(): void
    {
        $dir = sys_get_temp_dir().'/nevela-launcher-'.bin2hex(random_bytes(4));
        $file = (new ResourceGenerator)->launcher('apps\\api');
        $report = (new Writer(['root' => $dir]))->write([$file]);

        $this->assertSame('nevela', $file->path);
        $this->assertSame('created', $report[0]['status']);
        $written = file_get_contents("{$dir}/nevela");
        $this->assertStringStartsWith("#!/usr/bin/env php\n<?php\n", $written);
        $this->assertStringContainsString("\$api = __DIR__.'/apps/api';", $written);
        $this->assertStringContainsString("\$command = ['nevela:'.\$name, ...\$args];", $written);

        exec('php -l '.escapeshellarg("{$dir}/nevela").' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        // With nothing to forward to, it prints help and says so with its exit code.
        exec('php '.escapeshellarg("{$dir}/nevela").' help 2>&1', $help, $helpCode);
        $this->assertSame(0, $helpCode);
        $this->assertStringContainsString('php nevela upgrade', implode("\n", $help));
        exec('php '.escapeshellarg("{$dir}/nevela").' nonsense 2>&1', $ignored, $unknownCode);
        $this->assertSame(1, $unknownCode);
    }

    public function test_regeneration_after_adding_a_field_only_touches_generated_blocks(): void
    {
        $dir = sys_get_temp_dir().'/nevela-regen-'.bin2hex(random_bytes(4));
        $roots = ['api' => "{$dir}/api", 'web' => "{$dir}/web"];
        $generator = new ResourceGenerator;
        (new Writer($roots))->write($generator->forResource($this->product(), '2026_10_02_000000'));

        $model = "{$dir}/api/app/Models/Product.php";
        file_put_contents($model, str_replace('// Your own relationships', "public function label(): string { return \$this->name; }\n    // Your own relationships", file_get_contents($model)));

        $more = Descriptor::fromSpec('Product', 'name:string, sku:string!, description:text?, price:money, stock:int?, active:boolean, kind:enum(stock|digital), launchOn:date?, website:url?, featured:boolean', 'package', 'Catalogue');
        $report = (new Writer($roots))->write($generator->forResource($more, '2026_10_03_000000'));
        $statuses = array_combine(array_map(fn ($p) => basename($p), array_column($report, 'path')), array_column($report, 'status'));

        $this->assertSame('updated', $statuses['Product.php']);
        $this->assertSame('exists', $statuses['2026_10_03_000000_create_products_table.php']);
        $this->assertSame('exists', $statuses['ProductPolicy.php']);
        $this->assertStringContainsString("'featured'", file_get_contents($model));
        $this->assertStringContainsString('public function label(): string', file_get_contents($model));
    }
}
