<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Fake;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

final class SeedCommand extends Command
{
    protected $signature = 'nevela:seed
        {name : The resource to fill, e.g. Product}
        {--count=1000 : How many records to create}
        {--chunk=500 : Rows per insert statement}
        {--fresh : Delete the resource\'s existing records first}';

    protected $description = 'Fill a resource with plausible records, and say how long it took';

    private const MAX_COUNT = 5_000_000;

    /** 400 blocks of MAX_COUNT stay under 2^31, so a unique integer column can hold the numbers. */
    private const BLOCKS = 400;

    private const ATTEMPTS = 5;

    public function handle(): int
    {
        try {
            $resource = Nevela::resource(Str::studly($this->argument('name')));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $count = filter_var($this->option('count'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_COUNT]]);
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($count === false || $chunk === false) {
            $this->components->error('--count must be between 1 and 5,000,000, and --chunk between 1 and 5,000.');

            return self::FAILURE;
        }

        // SQLite allows 32,766 bound values per statement; stay well inside it on wide tables.
        $chunk = min($chunk, max(1, intdiv(30_000, count($resource->fields) + 3)));
        $fresh = (bool) $this->option('fresh');

        $existing = $fresh ? 0 : (int) DB::table($resource->table)->count();
        foreach ($resource->fields as $field) {
            $capacity = Fake::capacity($field);
            if ($capacity !== null && $existing + $count > $capacity) {
                $room = number_format(max(0, $capacity - $existing));
                $this->components->error("\"{$field->name}\" is unique and can only hold ".number_format($capacity)." different values, so there is room for {$room} more {$resource->pluralLabel}, not ".number_format($count).'.');

                return self::FAILURE;
            }
        }

        $started = microtime(true);
        $removed = 0;
        $bar = $this->output->createProgressBar($count);
        try {
            for ($attempt = 1; ; $attempt++) {
                // Unique fields are numbered from a block of MAX_COUNT picked per run (a run is at
                // most that long). Two runs only meet if they pick the same block; then the insert
                // fails, the transaction rolls back and another block is picked.
                $offset = $fresh && $attempt === 1 ? 0 : mt_rand(0, self::BLOCKS - 1) * self::MAX_COUNT;
                $bar->start();
                try {
                    $removed = DB::transaction(fn () => $this->fill($resource, $count, $chunk, $offset, $fresh, $bar));
                    break;
                } catch (UniqueConstraintViolationException $e) {
                    if ($attempt === self::ATTEMPTS) {
                        throw $e;
                    }
                }
            }
            $bar->finish();
            $this->newLine(2);
        } catch (Throwable $e) {
            $this->newLine(2);
            $this->components->error("Seeding {$resource->pluralLabel} failed, and the table is as it was: ".Str::limit($e->getMessage(), 300));

            return self::FAILURE;
        }
        if ($fresh) {
            $this->components->info('Removed '.number_format($removed)." existing {$resource->pluralLabel}.");
        }

        $seconds = microtime(true) - $started;
        $time = $seconds < 1 ? round($seconds * 1000).' ms' : number_format($seconds, 2).' s';
        $rate = number_format($count / max($seconds, 0.001));
        $this->components->info('Seeded '.number_format($count)." {$resource->pluralLabel} in {$time} ({$rate} rows/s).");
        $this->components->twoColumnDetail("{$resource->pluralLabel} in the database", number_format(DB::table($resource->table)->count()));

        return self::SUCCESS;
    }

    /** Runs inside the transaction: the optional delete and every insert succeed or fail together. */
    private function fill(Descriptor $resource, int $count, int $chunk, int $offset, bool $fresh, ProgressBar $bar): int
    {
        $removed = $fresh ? DB::table($resource->table)->delete() : 0;
        $now = time();
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

        return $removed;
    }
}
