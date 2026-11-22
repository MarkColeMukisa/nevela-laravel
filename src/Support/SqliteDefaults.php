<?php

namespace Nevela\Laravel\Support;

/**
 * SQLite settings for an app that answers several requests at once.
 *
 * Laravel's own leave SQLite as it is out of the box: a write waits for no one, and the
 * database is locked to readers while it is written. A dashboard or a phone's home screen
 * asks for many things at once, and the rate limiter reads then writes its count, so they
 * met as "database is locked". These are what a busy SQLite app wants:
 *
 * - busy_timeout 5000: wait up to five seconds for another write to finish, not fail at once;
 * - journal_mode wal: readers carry on while something is written;
 * - synchronous normal: safe with WAL, and much quicker than full;
 * - transaction_mode IMMEDIATE: a transaction takes the write lock when it starts, so two
 *   that read and then write can't each wait for the other.
 *
 * Only where the app hasn't set its own: a value in config/database.php other than
 * Laravel's default is kept, and DB_BUSY_TIMEOUT, DB_JOURNAL_MODE, DB_SYNCHRONOUS and
 * DB_TRANSACTION_MODE change any of them.
 */
final class SqliteDefaults
{
    public const DEFAULTS = [
        'busy_timeout' => ['DB_BUSY_TIMEOUT', 5000],
        'journal_mode' => ['DB_JOURNAL_MODE', 'wal'],
        'synchronous' => ['DB_SYNCHRONOUS', 'normal'],
        'transaction_mode' => ['DB_TRANSACTION_MODE', 'IMMEDIATE'],
    ];

    /**
     * The connections with the defaults filled in.
     *
     * @param  array<string, mixed>  $connections  config('database.connections')
     * @param  callable(string): (string|null)  $env  An environment variable, or null when it isn't set
     * @return array<string, mixed>
     */
    public static function apply(array $connections, callable $env): array
    {
        foreach ($connections as $name => $connection) {
            if (! is_array($connection) || ($connection['driver'] ?? null) !== 'sqlite') {
                continue;
            }
            foreach (self::DEFAULTS as $key => [$variable, $default]) {
                $set = $env($variable);
                if ($set !== null && $set !== '') {
                    $connections[$name][$key] = $key === 'busy_timeout' ? (int) $set : $set;
                } elseif (! isset($connection[$key]) || ($key === 'transaction_mode' && strtoupper((string) $connection[$key]) === 'DEFERRED')) {
                    // DEFERRED is what Laravel's config/database.php writes, so it says nothing of the app's own wishes.
                    $connections[$name][$key] = $default;
                }
            }
        }

        return $connections;
    }
}
