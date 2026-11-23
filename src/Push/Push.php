<?php

namespace Nevela\Laravel\Push;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Nevela\Laravel\Auth\Sessions;
use Nevela\Laravel\Support\SchemaFacts;
use RuntimeException;
use Throwable;

/**
 * Push notifications to people's phones, through Expo's push service, which hands them to
 * Firebase for Android and to Apple for iPhones.
 *
 *     Push::send([$user->id], 'Your order JM-0016 is ready', 'Collect it at the counter', '/dashboard/orders/'.$order->id);
 *
 * Every bell notice already goes out as one (NevelaServiceProvider listens for NoticeSent), so
 * this is for a push with no notice beside it.
 *
 * A push never gets in the way of the work that caused it: nothing is asked of Expo for people
 * with no phone registered, Expo is given a few seconds, and a failure is reported and passed
 * over. A phone Expo says is gone (the app was removed, or its notifications were reset) is
 * forgotten, so it isn't tried again.
 */
final class Push
{
    public const TABLE = 'nevela_push_tokens';

    public const SEND = 'https://exp.host/--/api/v2/push/send';

    public const RECEIPTS = 'https://exp.host/--/api/v2/push/getReceipts';

    /** "ExponentPushToken[…]", or the older "ExpoPushToken[…]". */
    public const TOKEN = '/^Expo(nent)?PushToken\[[^\]]+\]$/';

    /** Expo takes at most this many messages in one request. */
    private const BATCH = 100;

    /** Whether the table is there: before `migrate` it isn't, and nothing is sent or reported. */
    public static function ready(): bool
    {
        return SchemaFacts::hasTable(self::TABLE);
    }

    /**
     * Tell these people (user ids), on every phone they're signed in on. Never throws.
     *
     * @param  iterable<int|string>  $userIds
     * @param  string|null  $url  Where a tap leads, as the bell's links say it: "/dashboard/orders/{id}"
     * @return list<array<string, mixed>> Expo's ticket for each phone, in order: for `nevela push`, which checks them
     */
    public static function send(iterable $userIds, string $title, ?string $body = null, ?string $url = null): array
    {
        try {
            $ids = collect($userIds)->filter(fn ($id) => $id !== null && $id !== '')->map(fn ($id) => (string) $id)->unique()->values();
            if ($ids->isEmpty() || ! self::ready()) {
                return [];
            }
            $tokens = self::live($ids->all());
            $tickets = [];
            foreach (array_chunk($tokens, self::BATCH) as $batch) {
                array_push($tickets, ...self::deliver($batch, $title, $body, $url));
            }

            return $tickets;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * What became of pushes Expo took (their tickets' ids): only a receipt says whether Firebase
     * or Apple took them in turn. Bad Firebase credentials show here and nowhere else.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>> id => receipt; an id still being worked on is missing
     */
    public static function receipts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $answer = self::expo()->post(self::RECEIPTS, ['ids' => array_values($ids)]);
        if (! $answer->successful()) {
            throw new RuntimeException("Expo answered {$answer->status()}: ".substr($answer->body(), 0, 300));
        }

        return (array) $answer->json('data', []);
    }

    /**
     * The phones of these people whose sign-in is still live. One that has ended (revoked,
     * past its time limit, or unused too long) hears nothing, even before the nightly clean-up
     * removes it: a phone signed out offline, which couldn't tell the server, stops once its
     * sign-in lapses.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public static function live(array $userIds): array
    {
        $rows = DB::table(self::TABLE)
            ->leftJoin(Sessions::TABLE, Sessions::TABLE.'.id', '=', self::TABLE.'.session_id')
            ->whereIn(self::TABLE.'.user_id', $userIds)
            ->get([self::TABLE.'.token', Sessions::TABLE.'.id as session', Sessions::TABLE.'.revoked_at', Sessions::TABLE.'.expires_at', Sessions::TABLE.'.last_seen_at']);

        return $rows->filter(fn ($row) => $row->session !== null && Sessions::ended($row) === null)->pluck('token')->values()->all();
    }

    /** Forget the phones of a sign-in that has ended. */
    public static function forgetSession(string $sessionId): void
    {
        if (self::ready()) {
            DB::table(self::TABLE)->where('session_id', $sessionId)->delete();
        }
    }

    /** Forget every phone of someone whose account is gone. */
    public static function forgetUser(string $userId): void
    {
        if (self::ready()) {
            DB::table(self::TABLE)->where('user_id', $userId)->delete();
        }
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array<string, mixed>>
     */
    private static function deliver(array $tokens, string $title, ?string $body, ?string $url): array
    {
        $messages = array_map(fn (string $token) => array_filter([
            'to' => $token,
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            // The channel the app makes on Android (lib/push.ts): without one, Android 8 and later show nothing.
            'channelId' => 'default',
            'priority' => 'high',
            'data' => $url === null ? null : ['url' => $url],
        ], fn ($value) => $value !== null), $tokens);

        try {
            $answer = self::expo()->post(self::SEND, $messages);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
        if (! $answer->successful()) {
            report(new RuntimeException("Expo push failed ({$answer->status()}): ".substr($answer->body(), 0, 300)));

            return [];
        }
        // One ticket per message, in the same order.
        $tickets = array_values((array) $answer->json('data', []));
        $gone = [];
        foreach ($tickets as $i => $ticket) {
            if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered' && isset($tokens[$i])) {
                $gone[] = $tokens[$i];
            }
        }
        if ($gone !== []) {
            DB::table(self::TABLE)->whereIn('token', $gone)->delete();
        }

        return $tickets;
    }

    private static function expo(): \Illuminate\Http\Client\PendingRequest
    {
        $request = Http::timeout(5)->acceptJson()->asJson();
        // Only needed when "enhanced push security" is on in the Expo project's settings.
        $token = (string) config('nevela.push.access_token');

        return $token === '' ? $request : $request->withToken($token);
    }
}
