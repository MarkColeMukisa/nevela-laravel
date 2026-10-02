<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Fake;
use Throwable;

final class SeedCommand extends Command
{
    protected $signature = 'nevela:seed
        {name : The resource to fill, e.g. Product}
        {--count=1000 : How many records to create}
        {--chunk=500 : Rows per insert statement}
        {--fresh : Delete the resource\'s existing records first}';

    protected $description = 'Fill a resource with plausible records, and say how long it took';

    public function handle(): int
    {
        try {
            $resource = Nevela::resource(Str::studly($this->argument('name')));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $count = filter_var($this->option('count'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5_000_000]]);
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($count === false || $chunk === false) {
            $this->components->error('--count must be between 1 and 5,000,000, and --chunk between 1 and 5,000.');

            return self::FAILURE;
        }

        $table = DB::table($resource->table);
        // SQLite allows 32,766 bound values per statement; stay well inside it on wide tables.
        $chunk = min($chunk, max(1, intdiv(30_000, count($resource->fields) + 3)));

        $started = microtime(true);
        try {
            if ($this->option('fresh')) {
                $removed = $table->delete();
                $this->components->info("Removed {$removed} existing {$resource->pluralLabel}.");
            }

            // Unique fields are numbered from past whatever is there, so seeding twice doesn't collide.
            $offset = (int) DB::table($resource->table)->count() + mt_rand(1, 9) * 1_000_000;
            $now = time();
            $bar = $this->output->createProgressBar($count);
            $bar->start();

            DB::transaction(function () use ($resource, $count, $chunk, $offset, $now, $bar) {
                for ($done = 0; $done < $count; $done += $chunk) {
                    $rows = [];
                    for ($i = $done; $i < min($count, $done + $chunk); $i++) {
                        // Spread over the last 60 days, so the dashboard's "this week" and trend have something to show.
                        $created = date('Y-m-d H:i:s', $now - mt_rand(0, 60 * 86400));
                        $rows[] = ['id' => (string) Str::uuid7()] + Fake::row($resource, $offset + $i + 1) + ['created_at' => $created, 'updated_at' => $created];
                    }
                    DB::table($resource->table)->insert($rows);
                    $bar->advance(count($rows));
                }
            });

            $bar->finish();
            $this->newLine(2);
        } catch (Throwable $e) {
            $this->newLine(2);
            $this->components->error("Seeding {$resource->pluralLabel} failed, and nothing was kept: ".Str::limit($e->getMessage(), 300));

            return self::FAILURE;
        }

        $seconds = microtime(true) - $started;
        $time = $seconds < 1 ? round($seconds * 1000).' ms' : number_format($seconds, 2).' s';
        $rate = number_format($count / max($seconds, 0.001));
        $this->components->info('Seeded '.number_format($count)." {$resource->pluralLabel} in {$time} ({$rate} rows/s).");
        $this->components->twoColumnDetail("{$resource->pluralLabel} in the database", number_format(DB::table($resource->table)->count()));

        return self::SUCCESS;
    }
}
