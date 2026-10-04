<?php

namespace Nevela\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nevela\Laravel\Media\Uploads;
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

        // --fresh deletes first, and a record others are required to belong to can't be deleted.
        // Said here, before any placeholder pictures are made for a run that can't happen.
        if ($fresh) {
            foreach (Nevela::all() as $other) {
                foreach ($other->fields as $field) {
                    if ($field->kind === 'belongsTo' && $field->target === $resource->name && $field->required && $other->name !== $resource->name
                        && ($children = (int) DB::table($other->table)->count()) > 0) {
                        $this->components->error(number_format($children)." {$other->pluralLabel} belong to the {$resource->pluralLabel} that --fresh would delete. Remove them first, or seed without --fresh.");

                        return self::FAILURE;
                    }
                }
            }
        }

        $started = microtime(true);
        try {
            $pools = $this->pools($resource);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
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
                    $removed = DB::transaction(fn () => $this->fill($resource, $count, $chunk, $offset, $fresh, $bar, $pools));
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

    /**
     * What a relation or an image field can be filled with: the records that exist to point
     * at, and a handful of pictures made for the purpose.
     *
     * @return array<string, list<string>>
     *
     * @throws InvalidArgumentException when a required field has nothing to draw from
     */
    private function pools(Descriptor $resource): array
    {
        $pools = [];
        foreach ($resource->fields as $field) {
            if ($field->kind === 'belongsTo') {
                $target = $field->target === $resource->name ? $resource : Nevela::resource((string) $field->target);
                $ids = DB::table($target->table)->inRandomOrder()->limit(1000)->pluck('id')->map(fn ($id) => (string) $id)->all();
                if ($field->unique) {
                    throw new InvalidArgumentException("\"{$field->name}\" is unique, so each {$target->label} can be used once. The seeder doesn't fill one-to-one relations; add those records yourself.");
                }
                if ($ids === [] && $field->required && $target->name !== $resource->name) {
                    throw new InvalidArgumentException("Every {$resource->label} needs a {$target->label}, and there are none yet. Seed them first: php artisan nevela:seed {$target->name}");
                }
                $pools[$field->name] = $ids;
            } elseif ($field->kind === 'file') {
                $pools[$field->name] = $field->isImage() ? Uploads::placeholders($resource, $field) : [];
                if ($pools[$field->name] === [] && $field->required) {
                    throw new InvalidArgumentException($field->isImage()
                        ? "\"{$field->name}\" is a required image, and PHP's GD extension isn't installed to make placeholder pictures with."
                        : "\"{$field->name}\" is a required file, which the seeder can't make up. Make it optional ({$field->name}:file(…)?) or add records yourself.");
                }
            }
        }

        return $pools;
    }

    /**
     * Runs inside the transaction: the optional delete and every insert succeed or fail together.
     *
     * @param  array<string, list<string>>  $pools
     */
    private function fill(Descriptor $resource, int $count, int $chunk, int $offset, bool $fresh, ProgressBar $bar, array $pools = []): int
    {
        $removed = $fresh ? DB::table($resource->table)->delete() : 0;
        $now = time();
        for ($done = 0; $done < $count; $done += $chunk) {
            $rows = [];
            for ($i = $done; $i < min($count, $done + $chunk); $i++) {
                // Spread over the last 60 days, so the dashboard's "this week" and trend have something to show.
                $created = date('Y-m-d H:i:s', $now - mt_rand(0, 60 * 86400));
                $rows[] = ['id' => (string) Str::uuid7()] + Fake::row($resource, $offset + $i + 1, $pools) + ['created_at' => $created, 'updated_at' => $created];
            }
            DB::table($resource->table)->insert($rows);
            $bar->advance(count($rows));
        }

        return $removed;
    }
}
