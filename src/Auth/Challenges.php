<?php

namespace Nevela\Laravel\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Things that are valid once and briefly: a sign-in waiting for its second step, an
 * emailed code, a passkey ceremony's challenge, a sign-in link.
 *
 * Kept in the cache, under a key made from a hash of the id, so reading the cache doesn't
 * give anyone a usable id. Codes are kept as hashes for the same reason.
 */
final class Challenges
{
    /** How many wrong codes are tolerated before the challenge is thrown away. */
    public const ATTEMPTS = 5;

    /**
     * @param  array<string, mixed>  $data
     * @return string The id to hand to the client
     */
    public static function start(string $type, array $data, int $seconds): string
    {
        $id = Str::random(48);
        Cache::put(self::key($type, $id), $data + ['attempts' => 0, 'expires' => time() + $seconds], $seconds);

        return $id;
    }

    /**
     * The same, under an id the caller chooses: an emailed code is found again by the
     * address it was sent to.
     *
     * @param  array<string, mixed>  $data
     */
    public static function put(string $type, string $id, array $data, int $seconds): void
    {
        Cache::put(self::key($type, $id), $data + ['attempts' => 0, 'expires' => time() + $seconds], $seconds);
    }

    /** @return array<string, mixed>|null */
    public static function get(string $type, ?string $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        $data = Cache::get(self::key($type, $id));

        return is_array($data) && ($data['expires'] ?? 0) >= time() ? $data : null;
    }

    /** Read it and make sure it can't be read again. @return array<string, mixed>|null */
    public static function take(string $type, ?string $id): ?array
    {
        $data = self::get($type, $id);
        if ($data !== null) {
            Cache::forget(self::key($type, (string) $id));
        }

        return $data;
    }

    /** @param array<string, mixed> $changes */
    public static function update(string $type, string $id, array $changes): void
    {
        $data = self::get($type, $id);
        if ($data !== null) {
            Cache::put(self::key($type, $id), $changes + $data, max(1, $data['expires'] - time()));
        }
    }

    /**
     * Count a wrong answer. Returns false when that was one too many, and the challenge
     * is gone: whoever is guessing has to start again.
     */
    public static function miss(string $type, string $id): bool
    {
        $data = self::get($type, $id);
        if ($data === null) {
            return false;
        }
        if ($data['attempts'] + 1 >= self::ATTEMPTS) {
            Cache::forget(self::key($type, $id));

            return false;
        }
        self::update($type, $id, ['attempts' => $data['attempts'] + 1]);

        return true;
    }

    public static function forget(string $type, string $id): void
    {
        Cache::forget(self::key($type, $id));
    }

    /**
     * Whether another emailed code may be sent for this purpose to this person.
     *
     * A six-digit code allows five wrong guesses, so the number of codes is what limits
     * guessing: at six codes an hour, trying every code takes several years, where
     * unlimited codes would take months. A person who really needs a seventh waits.
     */
    public static function mayIssue(string $type, string $who, int $perHour = 6): bool
    {
        $key = 'nevela:auth:issued:'.$type.':'.hash('sha256', $who);
        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }

    /** A six-digit code, and the hash of it to keep. @return array{0: string, 1: string} */
    public static function code(): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return [$code, self::hash($code)];
    }

    public static function hash(string $value): string
    {
        return hash_hmac('sha256', preg_replace('/\s+/', '', $value) ?? '', (string) config('app.key'));
    }

    private static function key(string $type, string $id): string
    {
        return 'nevela:auth:'.$type.':'.hash('sha256', $id);
    }
}
