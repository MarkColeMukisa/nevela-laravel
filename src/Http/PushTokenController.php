<?php

namespace Nevela\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nevela\Laravel\Auth\Sessions;
use Nevela\Laravel\Push\Push;

/**
 * The phone the app runs on, for push notifications: PUT _nevela/push-tokens when someone signs
 * in, DELETE _nevela/push-tokens/{token} when they sign out.
 *
 * A phone belongs to whoever signed in on it last: a second person signing in on the same phone
 * takes it over, so the first person's pushes stop going there. It is tied to that sign-in, and
 * goes when the sign-in ends however it ends: signed out, revoked from the Devices screen, a new
 * password, a closed account.
 */
final class PushTokenController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:'.Push::TOKEN],
            'platform' => ['nullable', 'string', 'in:android,ios'],
        ]);
        if (! Push::ready()) {
            return response()->json(['error' => 'Push notifications need their table first: php artisan migrate.', 'code' => 'NOT_MIGRATED'], 503);
        }
        $now = now();
        DB::table(Push::TABLE)->upsert(
            [[
                'token' => $data['token'],
                'user_id' => (string) $request->user()->getAuthIdentifier(),
                'session_id' => Sessions::of($request->user()->currentAccessToken()),
                'platform' => $data['platform'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['token'],
            ['user_id', 'session_id', 'platform', 'updated_at'],
        );

        return response()->json(['registered' => true]);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        // Only one's own: a token isn't secret enough to let anyone stop someone else's pushes.
        if (Push::ready()) {
            DB::table(Push::TABLE)->where('token', $token)->where('user_id', (string) $request->user()->getAuthIdentifier())->delete();
        }

        return response()->json(['registered' => false]);
    }
}
