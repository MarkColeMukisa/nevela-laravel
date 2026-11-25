<?php

namespace Nevela\Laravel\Tests\Unit;

use Nevela\Laravel\Generator\GeneratedFile;
use Nevela\Laravel\Generator\Writer;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/nevela-writer-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    private function file(string $body): GeneratedFile
    {
        return new GeneratedFile('api', 'a.php', "<?php\n// nevela:generated:start\n{$body}\n// nevela:generated:end\n");
    }

    private function write(GeneratedFile $file, bool $force = false): string
    {
        return (new Writer(['api' => $this->dir], $force))->write([$file])[0]['status'];
    }

    public function test_with_an_admin_app_each_web_file_goes_where_it_belongs(): void
    {
        mkdir("{$this->dir}/web");
        mkdir("{$this->dir}/admin");
        $roots = ['web' => "{$this->dir}/web", 'admin' => "{$this->dir}/admin"];
        $page = new GeneratedFile('web', 'app/dashboard/products/page.tsx', "export {};
", GeneratedFile::MODE_ONCE);
        $descriptor = new GeneratedFile('web', 'resources/product.resource.ts', "// nevela:generated:start
export {};
// nevela:generated:end
");
        $blog = new GeneratedFile('web', 'app/blog/page.tsx', "export {};
", GeneratedFile::MODE_ONCE);
        $latest = new GeneratedFile('web', 'resources/latest-posts.tsx', "// nevela:generated:start
export {};
// nevela:generated:end
");

        (new Writer($roots))->write([$page, $descriptor, $blog, $latest]);

        // The dashboard's pages in the admin alone; the descriptors in both; the site's parts in the site.
        $this->assertFileExists("{$this->dir}/admin/app/dashboard/products/page.tsx");
        $this->assertFileDoesNotExist("{$this->dir}/web/app/dashboard/products/page.tsx");
        $this->assertFileExists("{$this->dir}/web/resources/product.resource.ts");
        $this->assertFileExists("{$this->dir}/admin/resources/product.resource.ts");
        $this->assertFileExists("{$this->dir}/web/app/blog/page.tsx");
        $this->assertFileDoesNotExist("{$this->dir}/admin/app/blog/page.tsx");
        $this->assertFileExists("{$this->dir}/web/resources/latest-posts.tsx");
        $this->assertFileDoesNotExist("{$this->dir}/admin/resources/latest-posts.tsx");
    }

    public function test_without_an_admin_app_everything_web_goes_to_the_one_web_app(): void
    {
        mkdir("{$this->dir}/web");
        $page = new GeneratedFile('web', 'app/dashboard/products/page.tsx', "export {};
", GeneratedFile::MODE_ONCE);
        $this->assertSame('created', (new Writer(['web' => "{$this->dir}/web"]))->write([$page])[0]['status']);
        $this->assertFileExists("{$this->dir}/web/app/dashboard/products/page.tsx");
    }

    public function test_an_admin_only_project_gets_the_dashboard_and_skips_the_site(): void
    {
        mkdir("{$this->dir}/admin");
        $report = (new Writer(['admin' => "{$this->dir}/admin"]))->write([
            new GeneratedFile('web', 'app/dashboard/products/page.tsx', "export {};
", GeneratedFile::MODE_ONCE),
            new GeneratedFile('web', 'app/blog/page.tsx', "export {};
", GeneratedFile::MODE_ONCE),
        ]);
        $this->assertSame(['created', 'skipped'], array_column($report, 'status'));
        $this->assertFileExists("{$this->dir}/admin/app/dashboard/products/page.tsx");
    }

    public function test_creates_updates_and_keeps_code_outside_the_block(): void
    {
        $this->assertSame('created', $this->write($this->file('$a = 1;')));
        $this->assertSame('unchanged', $this->write($this->file('$a = 1;')));

        file_put_contents("{$this->dir}/a.php", file_get_contents("{$this->dir}/a.php")."// mine\n");
        $this->assertSame('updated', $this->write($this->file('$a = 2;')));

        $contents = file_get_contents("{$this->dir}/a.php");
        $this->assertStringContainsString('$a = 2;', $contents);
        $this->assertStringContainsString('// mine', $contents);
        $this->assertMatchesRegularExpression('/nevela:generated:start hash=[0-9a-f]{12}/', $contents);
    }

    public function test_skips_a_block_you_edited_unless_forced(): void
    {
        $this->write($this->file('$a = 1;'));
        file_put_contents("{$this->dir}/a.php", str_replace('$a = 1;', '$a = 99;', file_get_contents("{$this->dir}/a.php")));

        $this->assertSame('edited', $this->write($this->file('$a = 2;')));
        $this->assertStringContainsString('$a = 99;', file_get_contents("{$this->dir}/a.php"));
        $this->assertSame('updated', $this->write($this->file('$a = 2;'), force: true));
    }

    public function test_once_files_are_never_rewritten(): void
    {
        mkdir("{$this->dir}/m");
        file_put_contents("{$this->dir}/m/2026_01_01_000000_create_x_table.php", 'old');
        $file = new GeneratedFile('api', 'm/2026_10_02_000000_create_x_table.php', 'new', GeneratedFile::MODE_ONCE, 'm/*_create_x_table.php');

        $this->assertSame('exists', $this->write($file));
        $this->assertFileDoesNotExist("{$this->dir}/m/2026_10_02_000000_create_x_table.php");
    }

    public function test_tolerates_windows_line_endings(): void
    {
        $this->write($this->file('$a = 1;'));
        file_put_contents("{$this->dir}/a.php", str_replace("\n", "\r\n", file_get_contents("{$this->dir}/a.php")));

        $this->assertSame('unchanged', $this->write($this->file('$a = 1;')));
    }
}
