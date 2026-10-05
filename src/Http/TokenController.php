<?php

namespace Nevela\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nevela\Laravel\Auth\Account;

/**
 * The Sanctum token a client is signed in with: who it belongs to, and giving it up.
 *
 * The Next.js server keeps the token in an httpOnly cookie and sends it as
 * `Authorization: Bearer …` on every call to Laravel. The browser never sees it.
 * Getting a token is Auth\SignInController's job.
 */
final class TokenController
{
    /** GET auth/me */
    public function show(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        return response()->json([
            'user' => self::user($request->user()),
            // Which of the account's devices is asking: its id in GET auth/sessions.
            'session' => ['id' => $token && method_exists($token, 'getKey') ? (string) $token->getKey() : null],
        ]);
    }

    /** DELETE auth/token: sign this device out. */
    public function destroy(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->noContent();
    }

    /** What the dashboard needs to know about the signed-in user. */
    public static function user(mixed $user): array
    {
        return Account::describe($user);
    }
}
