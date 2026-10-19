<?php

namespace Nevela\Laravel\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a table, or a column, is in the database: asked of the database once, and
 * remembered across requests once the answer is yes.
 *
 * Nevela checks before using what a migration adds (the roles table, a table's trash
 * column), so an app that has been upgraded and not yet migrated keeps working. Those
 * checks were remembered only for one request, and PHP-FPM starts every request afresh,
 * so a deployed app asked the database's catalogue on every request. The benchmark
 * found two such questions on every read.
 *
 * A yes is kept in bootstrap/cache/nevela-schema.json, which every request reads once.
 * A no is never kept: the migration that changes it can run at any time. Running
 * migrations (migrate, rollback, fresh) forgets everything, so a table or column a
 * migration removed is asked about again. Something removed another way is forgotten
 * with `php artisan optimize:clear`, or by deleting the file.
 *
 * Tests remember within one process only, as before, unless `$persistInTests` is set.
 */
final class SchemaFacts
{
    /** @var array<string, true>|null What is known to be there; null until the file is read. */
    private static ?array $known = null;

    /** Keep the file in tests too, for the tests of this class. */
    public static bool $persistInTests = false;

    public static function hasTable(string $table, ?string $connection = null): bool
    {
        return self::knows($connection, $table, fn ($schema) => $schema->hasTable($table));
    }

    public static function hasColumn(string $table, string $column, ?string $connection = null): bool
    {
        return self::knows($connection, "{$table}.{$column}", fn ($schema) => $schema->hasColumn($table, $column));
    }

    /** Forget every yes: in this process, and in the file. */
    public static function forget(): void
    {
        self::$known = [];
        // Under the lock a write takes, so a write that read the file before this can't
        // put back afterwards what was just forgotten.
        self::locked(function () {
            if (is_file($path = self::path())) {
                @unlink($path);
            }
        });
    }

    /**
     * A new request in a process that outlives one (Octane, a queue worker): read the file
     * again before the next question, as PHP-FPM does by starting afresh. Another process
     * may have forgotten since: a migration run from the deploy script.
     */
    public static function refresh(): void
    {
        if (self::persists()) {
            self::$known = null;
        }
    }

    public static function path(): string
    {
        return app()->bootstrapPath('cache/nevela-schema.json');
    }

    /** @param  callable(\Illuminate\Database\Schema\Builder): bool  $ask */
    private static function knows(?string $connection, string $fact, callable $ask): bool
    {
        $db = DB::connection($connection);
        // The connection, its database and its table prefix: the same name in two databases
        // (or a test's), or under another prefix, is another table, and never shares an answer.
        $key = $db->getName().':'.$db->getDatabaseName().':'.$db->getTablePrefix().$fact;

        self::$known ??= self::read();
        if (isset(self::$known[$key])) {
            return true;
        }
        if (! $ask(Schema::connection($db->getName()))) {
            return false;
        }
        self::$known[$key] = true;
        self::write($key);

        return true;
    }

    /** @return array<string, true> */
    private static function read(): array
    {
        if (! self::persists() || ! is_file($path = self::path())) {
            return [];
        }
        $facts = json_decode((string) @file_get_contents($path), true);

        return is_array($facts) ? array_fill_keys(array_filter(array_keys($facts), 'is_string'), true) : [];
    }

    /** Add one yes to the file, with what is in it now (not what this process read earlier). */
    private static function write(string $key): void
    {
        if (! self::persists()) {
            return;
        }
        $path = self::path();
        if (! is_dir(dirname($path)) || ! is_writable(dirname($path))) {
            return;
        }
        // Read, add and replace under one lock, which forget() takes too.
        self::locked(function () use ($path, $key) {
            $facts = [...self::read(), $key => true];
            ksort($facts);
            $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
            if (@file_put_contents($temporary, json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n") === false) {
                return;
            }
            if (! @rename($temporary, $path)) {
                @unlink($temporary);
            }
        });
    }

    /** Run $then holding the file's lock, or without one where there is no file to keep. */
    private static function locked(callable $then): void
    {
        $handle = self::persists() ? @fopen(self::path().'.lock', 'c') : false;
        if ($handle === false) {
            $then();

            return;
        }
        try {
            flock($handle, LOCK_EX);
            $then();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function persists(): bool
    {
        return self::$persistInTests || ! app()->runningUnitTests();
    }
}
