<?php

namespace Nevela\Laravel\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Support\SchemaFacts;

/**
 * The addresses requests come from, as Nevela counts and blocks them (step 11, after Grit's
 * Sentinel): repeat offenders blocked for a while, and a block list an administrator keeps.
 *
 * - **Whose address.** A request the web app makes for a visitor is that visitor's, as the web
 *   app passes it on (X-Nevela-Client, believed only with the shared secret). One it makes for
 *   nobody in particular is counted and blocked for nobody: the web app's own server is never
 *   blocked, or everyone using the site would be.
 * - **One key per address.** IPv4 as it is; IPv6 by its /64, since one person usually holds a
 *   whole /64 and could change address on every request. `::ffff:1.2.3.4` is plain 1.2.3.4.
 * - **Strikes.** A scanner's probe, and a request beyond the per-address limit, are strikes.
 *   `block_after` strikes within `block_window` minutes block the address for `block_minutes`.
 * - **Never blocked:** this computer (127.0.0.1, ::1) and nevela.security.exempt (addresses and
 *   ranges such as 203.0.113.0/24: an office, a monitor).
 */
final class Addresses
{
    public const TABLE = 'nevela_blocked_addresses';

    /** The key a request is counted and blocked by, or null when it is for nobody in particular. */
    public static function of(Request $request): ?string
    {
        if (Account::fromDashboard($request)) {
            $visitor = (string) $request->header('X-Nevela-Client', '');

            return filter_var($visitor, FILTER_VALIDATE_IP) ? self::key($visitor) : null;
        }
        $ip = (string) $request->ip();

        return filter_var($ip, FILTER_VALIDATE_IP) ? self::key($ip) : null;
    }

    /** An address as it is counted: IPv4 as it is, IPv6 by its /64 ("2001:db8:1:2::/64"). */
    public static function key(string $ip): string
    {
        $ip = trim($ip);
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            return $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            $prefix = substr((string) $packed, 0, 8).str_repeat("\0", 8);

            return inet_ntop($prefix).'/64';
        }

        return $ip;
    }

    /** What someone typed, as a key: an address, or an IPv6 /64. Null when it is neither. */
    public static function parse(string $typed): ?string
    {
        $typed = trim($typed);
        if (preg_match('#^(.+)/64$#', $typed, $m) && filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::key($m[1]);
        }

        return filter_var($typed, FILTER_VALIDATE_IP) ? self::key($typed) : null;
    }

    /** Never blocked: this computer, and the app's exempt list. */
    public static function exempt(string $key): bool
    {
        $ip = str_ends_with($key, '/64') ? substr($key, 0, -3) : $key;
        if (in_array($ip, ['127.0.0.1', '::1', '::'], true)) {
            return true;
        }
        foreach ((array) config('nevela.security.exempt', []) as $entry) {
            if (is_string($entry) && self::within($ip, trim($entry))) {
                return true;
            }
        }

        return false;
    }

    /** Whether the table is there (`php nevela migrate` makes it). */
    public static function ready(): bool
    {
        return SchemaFacts::hasTable(self::TABLE);
    }

    /** The block on an address now, or null. Asked of the cache, which a block or an unblock sets at once. */
    public static function blocked(string $key): ?array
    {
        if (! self::ready()) {
            return null;
        }
        $found = Cache::remember(self::cacheKey($key), 60, function () use ($key) {
            $row = DB::table(self::TABLE)->where('address', $key)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('blocked_at')->first();

            return $row ? ['id' => (string) $row->id, 'until' => $row->expires_at ? Carbon::parse((string) $row->expires_at)->toAtomString() : null] : false;
        });
        if ($found === false || $found === null) {
            return null;
        }
        // Past its end: no longer a block, whatever the cache says.
        if ($found['until'] !== null && Carbon::parse($found['until'])->isPast()) {
            Cache::forget(self::cacheKey($key));

            return null;
        }

        return $found;
    }

    /**
     * A strike against an address: a scanner's probe, or a request beyond the per-address
     * limit. Too many in the window, and it is blocked.
     */
    public static function strike(?string $key, string $why): void
    {
        if ($key === null || self::exempt($key)) {
            return;
        }
        SecurityLog::record($key, $why);
        if (! self::ready() || self::blocked($key) !== null) {
            return;
        }
        $after = (int) config('nevela.security.block_after', 20);
        if ($after <= 0) {
            return;
        }
        $counter = 'nevela-strikes:'.$key;
        RateLimiter::hit($counter, max(60, (int) config('nevela.security.block_window', 10) * 60));
        if (RateLimiter::attempts($counter) >= $after) {
            RateLimiter::clear($counter);
            self::block($key, $why, (int) config('nevela.security.block_minutes', 60), null, null, $after);
        }
    }

    /**
     * Block an address: for `$minutes`, or until cleared (0 or null).
     *
     * @return string the block's id
     */
    public static function block(string $key, string $reason, ?int $minutes, ?string $by, ?string $note, int $strikes = 0): string
    {
        $id = (string) Str::uuid();
        $until = $minutes && $minutes > 0 ? now()->addMinutes($minutes) : null;
        DB::table(self::TABLE)->insert([
            'id' => $id,
            'address' => $key,
            'reason' => mb_substr($reason, 0, 20),
            'note' => $note === null ? null : mb_substr($note, 0, 255),
            'blocked_by' => $by,
            'strikes' => $strikes,
            'blocked_at' => now(),
            'expires_at' => $until,
        ]);
        Cache::put(self::cacheKey($key), ['id' => $id, 'until' => $until?->toAtomString()], $until ? max(1, now()->diffInSeconds($until)) : 3600);
        SecurityLog::blocked($key, $reason, $by, $note);

        return $id;
    }

    /** Clear the blocks on an address, or one block by its id. How many there were. `$by`: who, by user id. */
    public static function unblock(string $keyOrId, ?string $by = null): int
    {
        if (! self::ready()) {
            return 0;
        }
        // An id is a uuid, which Postgres won't compare with an address: one or the other.
        $rows = DB::table(self::TABLE)->where(Str::isUuid($keyOrId) ? 'id' : 'address', $keyOrId)->get(['id', 'address']);
        foreach ($rows->pluck('address')->unique() as $address) {
            Cache::forget(self::cacheKey((string) $address));
            RateLimiter::clear('nevela-strikes:'.$address);
            SecurityLog::unblocked((string) $address, $by);
        }
        DB::table(self::TABLE)->whereIn('id', $rows->pluck('id'))->delete();

        return $rows->count();
    }

    /** The blocks in force, newest first. @return list<array<string, mixed>> */
    public static function current(): array
    {
        if (! self::ready()) {
            return [];
        }

        return DB::table(self::TABLE)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('blocked_at')->get()->map(fn ($row) => [
            'id' => (string) $row->id,
            'address' => (string) $row->address,
            'reason' => (string) $row->reason,
            'note' => $row->note,
            'blockedBy' => $row->blocked_by,
            'strikes' => (int) $row->strikes,
            'blockedAt' => Carbon::parse((string) $row->blocked_at)->toAtomString(),
            'until' => $row->expires_at ? Carbon::parse((string) $row->expires_at)->toAtomString() : null,
        ])->all();
    }

    /** Blocks that have ended, removed: the nightly run. */
    public static function prune(): int
    {
        return self::ready() ? DB::table(self::TABLE)->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete() : 0;
    }

    private static function cacheKey(string $key): string
    {
        return 'nevela-blocked:'.$key;
    }

    /** Whether an address is the one named, or within a range ("203.0.113.0/24", "2001:db8::/32"). */
    private static function within(string $ip, string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return self::key($entry) === self::key($ip) || $entry === $ip;
        }
        [$net, $bits] = explode('/', $entry, 2);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b) || ! ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);

        return (ord($a[$bytes]) & ord($mask)) === (ord($b[$bytes]) & ord($mask));
    }
}
