<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Generator\Writer;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Descriptor;

final class GenerateCommand extends Command
{
    protected $signature = 'nevela:generate
        {name? : Only this resource (e.g. Product). Default: every descriptor}
        {--force : Rewrite generated blocks even where you edited inside them}';

    protected $description = 'Regenerate Laravel code and Flare descriptors from nevela/resources/*.json';

    public function handle(): int
    {
        Nevela::forget();
        try {
            $all = Nevela::all();
            $targets = $this->argument('name') ? [Nevela::resource($this->argument('name'))] : $all;
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        // With no descriptors there is still an (empty) route file and web registry to
        // write, so a new app's dashboard builds before its first resource exists.
        $status = self::generate($this, $targets, $all, (bool) $this->option('force'));
        if ($all === []) {
            $this->components->warn('No descriptors yet. Start with: php artisan nevela:resource Product --fields="name:string, price:money"');
        }

        return $status;
    }

    /** @param list<Descriptor> $targets @param list<Descriptor> $all */
    public static function generate(Command $command, array $targets, array $all, bool $force): int
    {
        $generator = new ResourceGenerator;
        $files = [];
        foreach ($targets as $descriptor) {
            array_push($files, ...$generator->forResource($descriptor));
        }
        $files[] = $generator->routes($all);
        $files[] = $generator->registry($all);

        $roots = ['api' => base_path()];
        if (($web = config('nevela.web_path')) && is_dir($web)) {
            $roots['web'] = $web;
        }

        $report = (new Writer($roots, $force))->write($files);
        $edited = 0;
        foreach ($report as $row) {
            $path = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $row['path']);
            $command->components->twoColumnDetail($path, match ($row['status']) {
                'created' => '<fg=green>created</>',
                'updated' => '<fg=blue>updated</>',
                'edited' => '<fg=yellow>skipped — edited inside generated block</>',
                'exists' => '<fg=gray>exists (yours)</>',
                'skipped' => '<fg=gray>skipped (no web app at nevela.web_path yet)</>',
                default => '<fg=gray>unchanged</>',
            });
            $edited += $row['status'] === 'edited' ? 1 : 0;
        }
        if ($edited) {
            $command->components->warn("{$edited} file(s) skipped because you edited their generated block. Move your changes outside it, or rerun with --force.");
        }

        return self::SUCCESS;
    }
}
