<?php

namespace Nevela\Laravel\Auth;

use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Access\ClosedAccounts;
use Nevela\Laravel\Support\SchemaFacts;

/**
 * Sign-ins that end, as Grit's do: a short access token, and a refresh token that is
 * swapped for a new one each time it is used.
 *
 * A session is one signed-in device, a row in nevela_sessions. Its access tokens are
 * Sanctum tokens named after it ("nevela-session:<id>") that expire in minutes, so a
 * token copied out of a browser soon stops working. Before one runs out, the dashboard
 * swaps the refresh token for a new pair (POST auth/refresh). That is what keeps
 * someone who is working signed in, and it is also where a sign-in ends:
 *
 * - **idle**: not refreshed for `nevela.auth.session.idle` minutes,
 * - **absolute**: `nevela.auth.session.absolute` minutes after it began, however busy,
 * - **revoked**: signed out, from this device or another, or by an administrator,
 * - **replayed**: a refresh token that was already swapped is presented again, however
 *   many swaps ago. Only the holder of the current one can refresh, so whoever comes
 *   second is using a copy. The session ends for both, which shows the theft instead of
 *   sharing the account.
 *
 * Revoking a session deletes its access tokens, so a sign-out takes effect at once.
 */
final class Sessions
{
    public const TABLE = 'nevela_sessions';

    /** Every refresh token a session has given up, by hash, so that any of them ends it if it comes back. */
    public const SPENT = 'nevela_spent_refresh_tokens';

    /** The start of an access token's name: the rest is its session's id. */
    public const TOKEN_PREFIX = 'nevela-session:';

    /** Whether the app has run the migration. Until it has, sign-ins are plain Sanctum tokens, as before. */
    public static function ready(): bool
    {
        return SchemaFacts::hasTable(self::TABLE);
    }

    public static function accessMinutes(): int
    {
        return max(1, (int) (config('nevela.auth.session.access') ?: 15));
    }

    /**
     * Minutes, or null for no limit. Only a limit set to null or 0 is none: a config with
     * no such setting, such as one published before 0.12, gets the package's.
     */
    public static function idleMinutes(): ?int
    {
        return self::minutes(config('nevela.auth.session.idle', 60 * 24 * 7));
    }

    /** Minutes, or null for no limit, as idleMinutes(). */
    public static function absoluteMinutes(): ?int
    {
        return self::minutes(config('nevela.auth.session.absolute', 60 * 24 * 30));
    }

    private static function minutes(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Sign a user in on this device: a session, its first access token and its refresh token.
     *
     * @return array{token: string, expiresAt: string, refreshToken: string, refreshExpiresAt: string|null, session: string}
     */
    public static function start(mixed $user, Request $request, string $name): array
    {
        $refresh = self::newRefreshToken();
        $now = now();
        $absolute = self::absoluteMinutes();
        [$ip, $agent] = Account::caller($request);
        $session = (object) [
            'id' => (string) Str::uuid(),
            'user_id' => (string) $user->getAuthIdentifier(),
            'token_hash' => self::hash($refresh),
            'previous_token_hash' => null,
            'rotated_at' => null,
            'name' => mb_substr($name, 0, 255),
            'user_agent' => $agent,
            'ip_address' => $ip,
            'created_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $absolute ? $now->copy()->addMinutes($absolute) : null,
            'revoked_at' => null,
        ];
        DB::table(self::TABLE)->insert((array) $session);

        return self::issue($user, $session, $refresh);
    }

    /**
     * POST auth/refresh: a new pair for a refresh token, which is no use again afterwards.
     *
     * @return array{token: string, expiresAt: string, refreshToken: string|null, refreshExpiresAt: string|null, session: string}
     */
    public static function refresh(string $refresh, Request $request): array
    {
        $hash = self::hash($refresh);
        $session = DB::table(self::TABLE)->where('token_hash', $hash)->first();
        if (! $session) {
            return self::presentedAgain($hash);
        }
        if ($why = self::ended($session)) {
            throw self::refused($why);
        }
        $user = self::owner($session);

        $next = self::newRefreshToken();
        [$ip, $agent] = Account::caller($request);
        $now = now();
        // Only if the token is still the current one: of two requests that set out with it
        // at the same moment, one swaps it, and the other is the case below.
        $swapped = DB::table(self::TABLE)->where('id', $session->id)->where('token_hash', $hash)->update([
            'token_hash' => self::hash($next),
            'previous_token_hash' => $hash,
            'rotated_at' => $now,
            'last_seen_at' => $now,
            'ip_address' => $ip,
            'user_agent' => $agent,
        ]);
        if ($swapped === 0) {
            return self::presentedAgain($hash);
        }
        if (SchemaFacts::hasTable(self::SPENT)) {
            DB::table(self::SPENT)->insertOrIgnore(['token_hash' => $hash, 'session_id' => $session->id, 'spent_at' => $now]);
        }
        $session->last_seen_at = $now;

        return self::issue($user, $session, $next);
    }

    /**
     * A refresh token that is not the current one. If it is the one just replaced, and only
     * moments ago, a second request set out with it at the same time as the one that
     * swapped it: that is given an access token, and no refresh token, so the new one stays
     * the only one. Later than that, or a token from swaps before, it is a copy, and the
     * sign-in ends: a thief who has refreshed many times since is signed out with the owner.
     */
    private static function presentedAgain(string $hash): array
    {
        $session = DB::table(self::TABLE)->where('previous_token_hash', $hash)->first();
        $justReplaced = $session !== null;
        if (! $session && SchemaFacts::hasTable(self::SPENT)) {
            $id = DB::table(self::SPENT)->where('token_hash', $hash)->value('session_id');
            $session = $id !== null ? DB::table(self::TABLE)->where('id', $id)->first() : null;
        }
        if (! $session) {
            throw self::refused('SESSION_REVOKED');
        }
        if ($why = self::ended($session)) {
            throw self::refused($why);
        }
        $grace = max(0, (int) config('nevela.auth.session.reuse_seconds', 60));
        if ($justReplaced && $session->rotated_at && Carbon::parse((string) $session->rotated_at)->addSeconds($grace)->gte(now())) {
            return self::issue(self::owner($session), $session, null);
        }

        self::revoke($session->id);
        throw self::refused('SESSION_REPLAYED');
    }

    /**
     * A new access token for a session. It lasts `access` minutes, and never past the
     * session's own end.
     *
     * @return array{token: string, expiresAt: string, refreshToken: string|null, refreshExpiresAt: string|null, session: string}
     */
    private static function issue(mixed $user, object $session, ?string $refresh): array
    {
        $expires = now()->addMinutes(self::accessMinutes());
        $end = self::endsAt($session);
        if ($end !== null && $end->lt($expires)) {
            $expires = $end;
        }
        $token = $user->createToken(self::TOKEN_PREFIX.$session->id, ['*'], $expires);

        return [
            'token' => $token->plainTextToken,
            'expiresAt' => $expires->toJSON(),
            'refreshToken' => $refresh,
            'refreshExpiresAt' => $end?->toJSON(),
            'session' => (string) $session->id,
        ];
    }

    /** When a session ends if it isn't used again: the idle limit from its last use, or its absolute end, whichever is first. */
    public static function endsAt(object $session): ?CarbonInterface
    {
        $ends = [];
        if ($idle = self::idleMinutes()) {
            $ends[] = Carbon::parse((string) $session->last_seen_at)->addMinutes($idle);
        }
        if ($session->expires_at) {
            $ends[] = Carbon::parse((string) $session->expires_at);
        }
        usort($ends, fn ($a, $b) => $a <=> $b);

        return $ends[0] ?? null;
    }

    /** Why a session can't be used any more, or null while it can. */
    public static function ended(object $session): ?string
    {
        if ($session->revoked_at) {
            return 'SESSION_REVOKED';
        }
        if ($session->expires_at && Carbon::parse((string) $session->expires_at)->isPast()) {
            return 'SESSION_EXPIRED';
        }
        $idle = self::idleMinutes();
        if ($idle && Carbon::parse((string) $session->last_seen_at)->addMinutes($idle)->isPast()) {
            return 'SESSION_IDLE';
        }

        return null;
    }

    /** The session's user, if they may still be signed in. If not, the session ends. */
    private static function owner(object $session): mixed
    {
        $user = Account::model()::query()->find($session->user_id);
        if (! $user || ClosedAccounts::isClosed($user) || ! Access::active($user)) {
            self::revoke($session->id);
            throw self::refused('SESSION_REVOKED');
        }

        return $user;
    }

    private static function refused(string $code): HttpResponseException
    {
        $error = match ($code) {
            'SESSION_IDLE' => 'You were signed out after a while away. Sign in again to carry on.',
            'SESSION_EXPIRED' => 'Your sign-in reached its time limit. Sign in again to carry on.',
            'SESSION_REPLAYED' => 'For your safety you were signed out: this sign-in was used from somewhere else. Sign in again.',
            default => 'You were signed out. Sign in again to carry on.',
        };

        return new HttpResponseException(response()->json(['error' => $error, 'code' => $code], 401));
    }

    /** End a session: it can't be refreshed, and its access tokens stop working at once. */
    public static function revoke(string $id): void
    {
        DB::table(self::TABLE)->where('id', $id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        DB::table('personal_access_tokens')->where('name', self::TOKEN_PREFIX.$id)->delete();
    }

    /**
     * End every session of a user, except `$keep`. Returns how many ended.
     */
    public static function revokeAll(mixed $user, ?string $keep = null): int
    {
        if (! self::ready()) {
            return 0;
        }
        $ids = DB::table(self::TABLE)->where('user_id', (string) $user->getAuthIdentifier())->whereNull('revoked_at')
            ->when($keep !== null, fn ($query) => $query->where('id', '!=', $keep))->pluck('id');
        foreach ($ids as $id) {
            self::revoke((string) $id);
        }

        return $ids->count();
    }

    /** The session an access token belongs to, or null for a token that belongs to none. */
    public static function of(mixed $token): ?string
    {
        $name = is_object($token) ? (string) ($token->name ?? '') : '';

        return str_starts_with($name, self::TOKEN_PREFIX) ? substr($name, strlen(self::TOKEN_PREFIX)) : null;
    }

    /**
     * Whether Sanctum may accept a token it found valid (for one of a session's, that
     * includes its expiry). A token of a session that has ended is deleted with it, so
     * only the others need looking at: tokens from before sessions, or made by the app's
     * own code, which keep to the same idle and absolute limits, measured on the token.
     */
    public static function accepts(mixed $token): bool
    {
        if (self::of($token) !== null) {
            return true;
        }
        $created = $token->created_at ?? null;
        $used = $token->last_used_at ?? $created;
        if (($absolute = self::absoluteMinutes()) && $created && Carbon::parse((string) $created)->addMinutes($absolute)->isPast()) {
            return false;
        }
        if (($idle = self::idleMinutes()) && $used && Carbon::parse((string) $used)->addMinutes($idle)->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * A user's signed-in devices: their sessions that haven't ended, newest use first.
     *
     * @return list<object>
     */
    public static function live(mixed $user): array
    {
        if (! self::ready()) {
            return [];
        }
        $rows = DB::table(self::TABLE)->where('user_id', (string) $user->getAuthIdentifier())->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')->get();

        return $rows->filter(fn ($row) => self::ended($row) === null)->values()->all();
    }

    /**
     * For the Users screen: how many devices each user is signed in on, and when one was last used.
     *
     * @param  list<string>  $userIds
     * @return array<string, array{devices: int, lastSeenAt: string|null}>
     */
    public static function devices(array $userIds): array
    {
        $found = [];
        if (! self::ready() || $userIds === []) {
            return $found;
        }
        foreach (DB::table(self::TABLE)->whereIn('user_id', $userIds)->whereNull('revoked_at')->get() as $row) {
            if (self::ended($row) !== null) {
                continue;
            }
            $mine = $found[(string) $row->user_id] ?? ['devices' => 0, 'lastSeenAt' => null];
            $mine['devices']++;
            if ($row->last_seen_at && ($mine['lastSeenAt'] === null || strtotime((string) $row->last_seen_at) > strtotime($mine['lastSeenAt']))) {
                $mine['lastSeenAt'] = (string) $row->last_seen_at;
            }
            $found[(string) $row->user_id] = $mine;
        }

        return $found;
    }

    /**
     * Remove what can never be used again: access tokens of sessions a day past their
     * expiry, and sessions that ended more than a day ago. Returns how many sessions went.
     */
    public static function prune(): int
    {
        if (! self::ready()) {
            return 0;
        }
        $dayAgo = now()->subDay();
        DB::table('personal_access_tokens')->where('name', 'like', self::TOKEN_PREFIX.'%')->where('expires_at', '<', $dayAgo)->delete();

        $gone = 0;
        DB::table(self::TABLE)->chunkById(500, function ($rows) use (&$gone, $dayAgo) {
            $ids = [];
            foreach ($rows as $row) {
                $endedAt = $row->revoked_at ? Carbon::parse((string) $row->revoked_at) : (self::ended($row) !== null ? self::endsAt($row) : null);
                if ($endedAt !== null && $endedAt->lt($dayAgo)) {
                    $ids[] = $row->id;
                }
            }
            if ($ids !== []) {
                $gone += DB::table(self::TABLE)->whereIn('id', $ids)->delete();
                DB::table('personal_access_tokens')->whereIn('name', array_map(fn ($id) => self::TOKEN_PREFIX.$id, $ids))->delete();
            }
        });

        // The spent tokens of sessions that are gone, by this or by an account being deleted.
        if (SchemaFacts::hasTable(self::SPENT)) {
            DB::table(self::SPENT)->whereNotExists(fn ($query) => $query->from(self::TABLE)->whereColumn(self::TABLE.'.id', self::SPENT.'.session_id'))->delete();
        }

        return $gone;
    }

    private static function newRefreshToken(): string
    {
        return Str::random(64);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
