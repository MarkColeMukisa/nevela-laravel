<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Support\DashboardState;
use Nevela\Laravel\Support\DashboardUpdate;
use Nevela\Laravel\Support\TemplateSource;
use PHPUnit\Framework\TestCase;

final class DashboardUpdateTest extends TestCase
{
    /** @param array<string, string|null> $contents */
    private function prints(array $contents): array
    {
        return array_map(fn ($value) => $value === null ? null : DashboardUpdate::hash($value), $contents);
    }

    private function plan(array $base, array $next, array $yours): array
    {
        return DashboardUpdate::plan($this->prints($base), $this->prints($next), $this->prints($yours));
    }

    public function test_only_files_you_did_not_change_are_updated(): void
    {
        $base = ['a.ts' => 'a1', 'b.ts' => 'b1', 'c.ts' => 'c1', 'same.ts' => 's'];
        $next = ['a.ts' => 'a2', 'b.ts' => 'b2', 'c.ts' => 'c1', 'same.ts' => 's'];
        $yours = ['a.ts' => 'a1', 'b.ts' => 'b-mine', 'c.ts' => 'c-mine', 'same.ts' => 's'];

        $this->assertSame([
            'a.ts' => DashboardUpdate::UPDATE,    // untouched by you, changed in the template
            'b.ts' => DashboardUpdate::CONFLICT,  // changed by both
            // c.ts: changed by you only, so it stands. same.ts: nothing to do.
        ], $this->plan($base, $next, $yours));
    }

    public function test_new_files_are_added_and_files_you_deleted_stay_deleted(): void
    {
        $base = ['old.ts' => 'x', 'deleted-by-you.ts' => 'd'];
        $next = ['old.ts' => 'x', 'deleted-by-you.ts' => 'd2', 'brand-new.ts' => 'n'];
        $yours = ['old.ts' => 'x', 'deleted-by-you.ts' => null, 'brand-new.ts' => null];

        $this->assertSame(['brand-new.ts' => DashboardUpdate::ADD], $this->plan($base, $next, $yours));
    }

    public function test_a_file_you_created_where_the_template_now_has_one_is_kept(): void
    {
        // The template gains lib/money.ts; you already wrote your own file with that name.
        $this->assertSame(['lib/money.ts' => DashboardUpdate::CONFLICT], $this->plan([], ['lib/money.ts' => 'theirs'], ['lib/money.ts' => 'mine']));
    }

    public function test_files_dropped_from_the_template_are_reported_only_when_you_left_them_alone(): void
    {
        $base = ['gone.ts' => 'g', 'gone-but-edited.ts' => 'e'];
        $yours = ['gone.ts' => 'g', 'gone-but-edited.ts' => 'mine'];

        $this->assertSame(['gone.ts' => DashboardUpdate::REMOVED], $this->plan($base, [], $yours));
    }

    public function test_line_endings_alone_are_not_a_change(): void
    {
        $base = ['a.ts' => "one\ntwo\n"];
        $next = ['a.ts' => "one\ntwo\nthree\n"];
        $yours = ['a.ts' => "one\r\ntwo\r\n"]; // what git on Windows may check out

        $this->assertSame(['a.ts' => DashboardUpdate::UPDATE], $this->plan($base, $next, $yours));
        $this->assertSame([], $this->plan($base, $base, $yours));
    }

    public function test_an_app_already_on_the_new_template_needs_nothing(): void
    {
        $next = ['a.ts' => 'a2', 'b.ts' => 'b2'];

        $this->assertSame([], $this->plan(['a.ts' => 'a1'], $next, $next));
    }

    public function test_your_changes_are_listed_from_the_recorded_fingerprints(): void
    {
        $base = $this->prints(['a.ts' => 'a', 'b.ts' => 'b', 'c.ts' => 'c']);
        $yours = $this->prints(['a.ts' => 'a', 'b.ts' => 'b changed', 'c.ts' => null]);

        $this->assertSame(['changed' => ['b.ts'], 'deleted' => ['c.ts']], DashboardUpdate::changes($base, $yours));
    }

    public function test_package_json_takes_the_template_versions_but_keeps_your_name_and_your_pins(): void
    {
        $base = json_encode(['name' => 'nevela-web', 'dependencies' => ['next' => '16.0.4', 'react' => '19.3.0', 'sonner' => '^2.0.8']]);
        $next = json_encode(['name' => 'nevela-web', 'dependencies' => ['next' => '16.1.0', 'react' => '19.4.0', 'sonner' => '^2.0.8', 'zod' => '^4.0.0']]);
        $yours = json_encode(['name' => 'shop-web', 'dependencies' => ['next' => '16.0.4', 'react' => '19.3.5', 'sonner' => '^2.0.8', 'stripe' => '^18.0.0']]);

        $merged = DashboardUpdate::mergePackageJson($yours, $base, $next);
        $result = json_decode($merged['json'], true);

        $this->assertSame('shop-web', $result['name']);
        $this->assertSame('16.1.0', $result['dependencies']['next']);     // you were on the old template's version
        $this->assertSame('19.3.5', $result['dependencies']['react']);    // you pinned your own: kept
        $this->assertSame('^4.0.0', $result['dependencies']['zod']);      // new in the template
        $this->assertSame('^18.0.0', $result['dependencies']['stripe']);  // yours: untouched
        $this->assertSame(['next', 'zod'], array_keys($merged['changed']));
        $this->assertSame(['react'], array_keys($merged['kept']));
        $this->assertStringStartsWith("{\n  \"name\": \"shop-web\",\n  \"dependencies\": {\n    \"next\"", $merged['json']);
        $this->assertStringEndsWith("}\n", $merged['json']);
    }

    public function test_without_a_record_of_the_old_dependencies_nothing_of_yours_is_overwritten(): void
    {
        $next = json_encode(['dependencies' => ['next' => '16.1.0', 'zod' => '^4.0.0']]);
        $yours = json_encode(['dependencies' => ['next' => '16.0.4']]);

        $merged = DashboardUpdate::mergePackageJson($yours, null, $next);

        $this->assertSame(['next' => '16.0.4', 'zod' => '^4.0.0'], json_decode($merged['json'], true)['dependencies']);
        $this->assertSame(['zod'], array_keys($merged['changed']));
        $this->assertSame(['next'], array_keys($merged['kept']));
    }

    public function test_the_record_round_trips_and_ignores_the_files_the_installer_personalises(): void
    {
        $web = sys_get_temp_dir().'/nevela-state-'.bin2hex(random_bytes(4));
        mkdir("{$web}/lib", 0775, true);
        $template = [
            'package.json' => json_encode(['name' => 'nevela-web', 'dependencies' => ['next' => '16.0.4']]),
            'lib/site.ts' => 'name: "Nevela"',
            'lib/csv.ts' => 'csv',
            'lib/utils.ts' => 'utils',
        ];
        file_put_contents("{$web}/package.json", json_encode(['name' => 'shop-web']));
        file_put_contents("{$web}/lib/site.ts", 'name: "Shop"');
        file_put_contents("{$web}/lib/csv.ts", "csv");
        file_put_contents("{$web}/lib/utils.ts", 'utils, edited');

        $state = new DashboardState;
        $state->adopt('0.1.4', $template);
        $state->history[] = ['from' => '0.1.3', 'to' => '0.1.4'];
        $state->write($web);
        $read = DashboardState::read($web);

        $this->assertSame('0.1.4', $read->template);
        $this->assertSame(DashboardUpdate::hash('csv'), $read->files['lib/csv.ts']);
        $this->assertSame(['dependencies' => ['next' => '16.0.4']], $read->dependencies);
        $this->assertSame('0.1.4', $read->history[0]['to']);
        // The app's name in package.json and site.ts is the installer's doing, not an edit.
        $this->assertSame(['changed' => ['lib/utils.ts'], 'deleted' => []], $read->yourChanges($web));
        $this->assertNull((new DashboardState('0.1.1'))->yourChanges($web));
    }

    public function test_the_repositorys_example_resources_are_not_part_of_the_template(): void
    {
        $files = TemplateSource::withoutExamples([
            'lib/csv.ts' => 'csv',
            'resources/index.ts' => 'registry',
            'resources/product.resource.ts' => 'defineResource({ name: "Product", slug: "products" })',
            'app/dashboard/products/page.tsx' => 'page',
            'app/dashboard/products/[id]/page.tsx' => 'detail',
            'app/dashboard/page.tsx' => 'home',
        ]);

        $this->assertSame(['lib/csv.ts', 'app/dashboard/page.tsx'], array_keys($files));
    }
}
