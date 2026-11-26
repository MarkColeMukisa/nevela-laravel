<?php

namespace Nevela\Laravel\Security;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Notices\Notice;
use Nevela\Laravel\Notices\Notices;
use Nevela\Laravel\Support\SchemaFacts;
use Throwable;

/**
 * What was turned away, and the blocks made and cleared (step 11, part 4).
 *
 * - **The log.** One row per address, kind and hour, with a count. Each event is one increment
 *   of a counter in the cache, as a rate limit is. The row is written when the count reaches 1,
 *   2, 5, 10, 20, 50, 100…, so a million requests are about eighteen writes, and recent() reads
 *   the live counter for the last two hours. Kept for `log_days` (30), then removed in the
 *   nightly run. It keeps the method, the path without its query (reset and sign-in tokens
 *   travel there) and the visitor's browser; never headers or a body.
 * - **The bell.** Whoever may see the block list is told when an address is blocked by itself,
 *   at most `notify_blocks_per_hour` (5) times an hour; past that, the spike warning speaks for
 *   the rest. A spike is about `spike_after` (300) refusals within five minutes, from anywhere,
 *   counted as rows are written (never on every request), told at most once an hour. Every
 *   notice also goes out as a push, which is why both are capped.
 *
 * Nothing here can fail the request it records: a problem is reported and the request goes on.
 */
final class SecurityLog
{
    public const TABLE = 'nevela_security_events';

    /** The kinds that are a request turned away, and count toward a spike. */
    private const REFUSALS = ['scanner', 'limit', 'sign-in', 'refused', 'locked'];

    public static function ready(): bool
    {
        return SchemaFacts::hasTable(self::TABLE);
    }

    /** Record one event against an address. `detail`: for a block, its reason and who. */
    public static function record(?string $address, string $kind, ?string $detail = null): void
    {
        if ($address === null || Addresses::exempt($address)) {
            return;
        }
        try {
            if (! self::ready()) {
                return;
            }
            $hour = now()->startOfHour();
            $key = self::counter($address, $kind, $hour);
            // One write to the cache for most events: a store without the key answers false.
            $count = Cache::increment($key);
            if ($count === false || (int) $count < 1) {
                Cache::add($key, 0, 7200);
                $count = Cache::increment($key);
            }
            $count = (int) $count;
            $previous = self::stepBefore($count);
            if ($previous === null) {
                return; // not on the ladder: recent() reads the live count meanwhile
            }
            self::write($address, $kind, $hour, $count, $detail);
            if (in_array($kind, self::REFUSALS, true)) {
                self::countTowardSpike($count - $previous);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** An address blocked: in the log, and in the bell when it blocked itself. */
    public static function blocked(string $address, string $reason, ?string $by, ?string $note): void
    {
        self::record($address, 'blocked', self::detail($reason, $by, $note));
        if ($by !== null || $reason === 'manual') {
            return; // whoever did it knows
        }
        try {
            $cap = max(0, (int) config('nevela.security.notify_blocks_per_hour', 5));
            $told = 'nevela-seclog-told:'.now()->format('YmdH');
            Cache::add($told, 0, 3600);
            if ($cap === 0 || (int) Cache::increment($told) > $cap) {
                return;
            }
            $why = ['scanner' => 'probing for files like /.env', 'limit' => 'too many requests', 'sign-in' => 'wrong passwords for many accounts'][$reason] ?? $reason;
            Notices::toPermission('blocked_addresses.view', new Notice(
                "Address blocked: {$address}",
                body: "Blocked by itself, for {$why}.",
                url: '/dashboard/access/blocked',
                tone: 'warning',
                kind: 'security.blocked',
                group: 'security.blocked',
                many: fn (int $count) => "{$count} addresses blocked",
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A block cleared, by someone or from the server. */
    public static function unblocked(string $address, ?string $by): void
    {
        self::record($address, 'unblocked', $by === null ? 'from the server' : 'by '.self::who($by));
    }

    /** A person's name for the log, by their id: kept as it was then, if they're renamed or removed. */
    private static function who(string $id): string
    {
        try {
            $user = Account::model()::query()->find($id);
        } catch (Throwable) {
            $user = null;
        }

        return trim((string) ($user->name ?? '')) ?: 'user '.$id;
    }

    /**
     * The log, newest first: the last `days`, or one address.
     *
     * @return list<array<string, mixed>>
     */
    public static function recent(int $days = 7, ?string $address = null, int $limit = 200): array
    {
        if (! self::ready()) {
            return [];
        }

        $rows = DB::table(self::TABLE)
            ->where('last_at', '>=', now()->subDays(max(1, $days)))
            ->when($address !== null, fn ($q) => $q->where('address', $address))
            ->orderByDesc('last_at')->limit($limit)->get();
        $recently = now()->subHours(2);

        return $rows->map(function ($row) use ($recently) {
            $count = (int) $row->count;
            $hour = Carbon::parse((string) $row->hour);
            // Between steps of the ladder, the cache has the true count: kept, once read.
            if ($hour->greaterThanOrEqualTo($recently)) {
                $live = (int) Cache::get(self::counter((string) $row->address, (string) $row->kind, $hour), 0);
                if ($live > $count) {
                    DB::table(self::TABLE)->where('id', $row->id)->where('count', '<', $live)->update(['count' => $live]);
                    $count = $live;
                }
            }

            return [
                'id' => (string) $row->id,
                'address' => (string) $row->address,
                'kind' => (string) $row->kind,
                'count' => $count,
                'method' => $row->method,
                'path' => $row->path,
                'userAgent' => $row->user_agent,
                'detail' => $row->detail,
                'firstAt' => Carbon::parse((string) $row->first_at)->toAtomString(),
                'lastAt' => Carbon::parse((string) $row->last_at)->toAtomString(),
            ];
        })->all();
    }

    /** Rows past `log_days`, removed: the nightly run. */
    public static function prune(): int
    {
        $days = max(1, (int) config('nevela.security.log_days', 30));

        return self::ready() ? DB::table(self::TABLE)->where('last_at', '<', now()->subDays($days))->delete() : 0;
    }

    private static function write(string $address, string $kind, Carbon $hour, int $count, ?string $detail): void
    {
        // A block made from the server (`php nevela security`) has no request worth keeping: a
        // request that came over HTTP has a method in its server variables, under Octane too.
        $request = app()->bound('request') ? app('request') : null;
        if ($request !== null && $request->server('REQUEST_METHOD') === null) {
            $request = null;
        }
        // For a visitor the web app speaks for, their browser, as sign-ins record it; not the web app's own fetch.
        $agent = '';
        if ($request !== null) {
            $passed = Account::fromDashboard($request) ? (string) $request->header('X-Nevela-User-Agent', '') : '';
            $agent = Str::limit($passed !== '' ? $passed : (string) $request->userAgent(), 255, '');
        }
        $now = now();
        $latest = [
            'count' => $count,
            'method' => $request ? Str::limit($request->method(), 10, '') : null,
            'path' => $request ? Str::limit('/'.ltrim($request->path(), '/'), 255, '') : null,
            'user_agent' => $agent === '' ? null : $agent,
            'detail' => $detail === null ? null : Str::limit($detail, 255, ''),
            'last_at' => $now,
        ];
        DB::table(self::TABLE)->upsert(
            [['id' => (string) Str::uuid(), 'address' => $address, 'kind' => $kind, 'hour' => $hour, 'first_at' => $now, ...$latest]],
            ['address', 'kind', 'hour'],
            array_keys($latest),
        );
    }

    /** The cache's counter for an address, a kind and an hour. */
    private static function counter(string $address, string $kind, Carbon $hour): string
    {
        return 'nevela-seclog:'.sha1($address.'|'.$kind.'|'.$hour->timestamp);
    }

    /**
     * The ladder the table is written on: 1, 2, 5, 10, 20, 50, 100… For a count on it, the step
     * before (0 for the first); null for a count between steps.
     */
    private static function stepBefore(int $count): ?int
    {
        if ($count === 1) {
            return 0;
        }
        $previous = 1;
        for ($scale = 1; $scale <= $count; $scale *= 10) {
            foreach ([1, 2, 5] as $step) {
                $at = $step * $scale;
                if ($at === $count) {
                    return $previous;
                }
                if ($at > $count) {
                    return null;
                }
                $previous = $at;
            }
        }

        return null;
    }

    /** Refusals since the row was last written, toward a spike: counted on writes, never per request. */
    private static function countTowardSpike(int $added): void
    {
        $after = max(0, (int) config('nevela.security.spike_after', 300));
        if ($after === 0 || $added < 1) {
            return;
        }
        $window = 'nevela-seclog-spike:'.intdiv(time(), 300);
        Cache::add($window, 0, 600);
        if ((int) Cache::increment($window, $added) < $after || ! Cache::add('nevela-seclog-spike-told', true, 3600)) {
            return;
        }
        Notices::toPermission('blocked_addresses.view', new Notice(
            'Many requests turned away',
            body: "{$after} or more in five minutes: probes, requests over the limit, or blocked addresses. See what was turned away.",
            url: '/dashboard/access/blocked',
            tone: 'warning',
            kind: 'security.spike',
        ));
    }

    private static function detail(string $reason, ?string $by, ?string $note): string
    {
        return trim($reason.($by !== null ? ' by '.self::who($by) : '').($note !== null && $note !== '' ? ': '.$note : ''));
    }
}
