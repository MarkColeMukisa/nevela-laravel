<?php

namespace Nevela\Laravel\Notices;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Console\DeployCheckCommand;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Releases;
use Throwable;

/**
 * What is wrong with the app right now, for its administrators: a migration not run, a
 * newer Nevela, and in production a scheduler, queue, mailer or cache that isn't doing
 * its job. Unlike a notice these aren't kept or read: each shows while it is true.
 *
 * The same checks as `nevela deploy:check`, kept cheap: worked out at most every five
 * minutes, and the newest release is only ever read from the cache, never fetched here.
 */
final class Conditions
{
    /** The newest release on Packagist, as last looked up (by the scheduler, `nevela status` or an upgrade). */
    public const LATEST = 'nevela:releases:latest';

    private const KEY = 'nevela:conditions';

    /** How long a job may wait before the queue is called stuck. */
    private const STUCK_AFTER = 15 * 60;

    /**
     * @return list<array{id: string, title: string, body: string, tone: string, url: string|null}>
     */
    public static function for(mixed $user): array
    {
        if (! Access::isAdmin($user)) {
            return [];
        }
        try {
            return Cache::remember(self::KEY, 300, fn () => self::check());
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Work them out again on the next request: after migrations, or an upgrade. */
    public static function forget(): void
    {
        try {
            Cache::forget(self::KEY);
        } catch (Throwable) {
            // No cache to forget from.
        }
    }

    /** Look up the newest release, for "Nevela x.y.z is out". The scheduler does it daily. */
    public static function lookUpRelease(): void
    {
        // Releases::latest() keeps what it finds under LATEST.
        if (Releases::latest() !== null) {
            self::forget();
        }
    }

    /** @return list<array{id: string, title: string, body: string, tone: string, url: string|null}> */
    private static function check(): array
    {
        $found = [];
        $add = function (string $id, string $title, string $body, string $tone = 'warning', ?string $url = null) use (&$found) {
            $found[] = ['id' => $id, 'title' => $title, 'body' => $body, 'tone' => $tone, 'url' => $url];
        };

        if (($pending = self::pendingMigrations()) > 0) {
            $add('migrations', $pending === 1 ? "A migration hasn't been run" : "{$pending} migrations haven't been run", 'Run php nevela migrate. Until then, what they add doesn\'t work.');
        }

        $latest = Cache::get(self::LATEST);
        if (is_string($latest) && version_compare($latest, Nevela::VERSION, '>')) {
            $add('release', "Nevela {$latest} is out", 'This app is on '.Nevela::VERSION.'. Run php nevela upgrade to move to it.', 'info', 'https://nevela-docs.vercel.app/reference/changelog/');
        }

        if (($waiting = self::stuckJobs()) > 0) {
            $add('queue', $waiting === 1 ? 'A background job is waiting' : "{$waiting} background jobs are waiting", 'Nothing has taken them for over 15 minutes, so form notices and confirmation emails are held up. Is a queue worker running?');
        }

        // What follows is how a local app is meant to be: only a problem on a real server.
        if (! app()->isProduction()) {
            return $found;
        }
        $at = Cache::get(DeployCheckCommand::HEARTBEAT);
        if (! is_int($at) || time() - $at > 15 * 60) {
            $add('scheduler', "The scheduler isn't running", 'The trash, ended sign-ins and the hourly digest wait for it. Add the cron line, or switch on the scheduler in Laravel Cloud.');
        }
        $mailer = (string) config('mail.default');
        if (in_array((string) config("mail.mailers.{$mailer}.transport", $mailer), ['log', 'array'], true)) {
            $add('mail', "Emails aren't being sent", "MAIL_MAILER is {$mailer}: sign-in links, codes and password resets go to the log instead of arriving.");
        }
        $store = (string) config('cache.default');
        if (in_array((string) config("cache.stores.{$store}.driver"), ['array', 'null'], true)) {
            $add('cache', 'The cache keeps nothing', "CACHE_STORE is {$store}: sign-in codes and links can't work, and rate limits don't count.");
        }

        return $found;
    }

    private static function pendingMigrations(): int
    {
        try {
            $migrator = app('migrator');
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
            $files = array_keys($migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')]));

            return count(array_diff($files, $ran));
        } catch (Throwable) {
            return 0;
        }
    }

    /** Jobs on a database queue that nothing has taken for a while. Other drivers can't be looked into. */
    private static function stuckJobs(): int
    {
        $connection = (string) config('queue.default');
        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return 0;
        }
        try {
            return DB::connection(config("queue.connections.{$connection}.connection"))
                ->table((string) config("queue.connections.{$connection}.table", 'jobs'))
                ->whereNull('reserved_at')
                ->where('available_at', '<', time() - self::STUCK_AFTER)
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
