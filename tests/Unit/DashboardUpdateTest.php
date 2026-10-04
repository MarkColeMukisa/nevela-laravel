<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Support\DashboardUpdate;
use PHPUnit\Framework\TestCase;

final class DashboardUpdateTest extends TestCase
{
    public function test_only_files_you_did_not_change_are_updated(): void
    {
        $base = ['a.ts' => 'a1', 'b.ts' => 'b1', 'c.ts' => 'c1', 'same.ts' => 's'];
        $next = ['a.ts' => 'a2', 'b.ts' => 'b2', 'c.ts' => 'c1', 'same.ts' => 's'];
        $yours = ['a.ts' => 'a1', 'b.ts' => 'b-mine', 'c.ts' => 'c-mine', 'same.ts' => 's'];

        $this->assertSame([
            'a.ts' => DashboardUpdate::UPDATE,    // untouched by you, changed in the template
            'b.ts' => DashboardUpdate::CONFLICT,  // changed by both
            // c.ts: changed by you only, so it stands. same.ts: nothing to do.
        ], DashboardUpdate::plan($base, $next, $yours));
    }

    public function test_new_files_are_added_and_files_you_deleted_stay_deleted(): void
    {
        $base = ['old.ts' => 'x', 'deleted-by-you.ts' => 'd'];
        $next = ['old.ts' => 'x', 'deleted-by-you.ts' => 'd2', 'brand-new.ts' => 'n'];
        $yours = ['old.ts' => 'x', 'deleted-by-you.ts' => null, 'brand-new.ts' => null];

        $this->assertSame(['brand-new.ts' => DashboardUpdate::ADD], DashboardUpdate::plan($base, $next, $yours));
    }

    public function test_files_dropped_from_the_template_are_reported_only_when_you_left_them_alone(): void
    {
        $base = ['gone.ts' => 'g', 'gone-but-edited.ts' => 'e'];
        $next = [];
        $yours = ['gone.ts' => 'g', 'gone-but-edited.ts' => 'mine'];

        $this->assertSame(['gone.ts' => DashboardUpdate::REMOVED], DashboardUpdate::plan($base, $next, $yours));
    }

    public function test_line_endings_alone_are_not_a_change(): void
    {
        $base = ['a.ts' => "one\ntwo\n"];
        $next = ['a.ts' => "one\ntwo\nthree\n"];
        $yours = ['a.ts' => "one\r\ntwo\r\n"]; // what git on Windows may check out

        $this->assertSame(['a.ts' => DashboardUpdate::UPDATE], DashboardUpdate::plan($base, $next, $yours));
        $this->assertSame([], DashboardUpdate::plan($base, $base, $yours));
    }

    public function test_an_app_already_on_the_new_template_needs_nothing(): void
    {
        $next = ['a.ts' => 'a2', 'b.ts' => 'b2'];

        $this->assertSame([], DashboardUpdate::plan(['a.ts' => 'a1'], $next, $next));
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
}
