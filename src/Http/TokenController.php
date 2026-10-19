<?php

namespace Nevela\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Auth\Sessions;

/**
 * The token a client is signed in with: who it belongs to, renewing it, and giving it up.
 *
 * The Next.js server keeps the tokens in httpOnly cookies and sends the access token as
 * `Authorization: Bearer …` on every call to Laravel. The browser never sees either.
 * Getting them is Auth\SignInController's job; how long they last is Auth\Sessions'.
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
            'session' => ['id' => self::deviceOf($token)],
        ]);
    }

    /** POST auth/refresh: a new access token, and a new refresh token in place of the one sent. */
    public function refresh(Request $request): JsonResponse
    {
        $refresh = $request->input('refreshToken');
        if (! is_string($refresh) || $refresh === '' || strlen($refresh) > 255 || ! Sessions::ready()) {
            return response()->json(['error' => 'Sign in again to carry on.', 'code' => 'SESSION_REVOKED'], 401);
        }
        $pair = Sessions::refresh($refresh, $request);
        unset($pair['session']);

        return response()->json($pair);
    }

    /** DELETE auth/token: sign this device out. Its session ends, with every token it has. */
    public function destroy(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();
        if (($session = Sessions::of($token)) !== null) {
            Sessions::revoke($session);
        } elseif ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->noContent();
    }

    /** A device's id in GET auth/sessions: its session, or the token itself for one that belongs to none. */
    public static function deviceOf(mixed $token): ?string
    {
        if (($session = Sessions::of($token)) !== null) {
            return $session;
        }

        return $token && method_exists($token, 'getKey') ? (string) $token->getKey() : null;
    }

    /** What the dashboard needs to know about the signed-in user. */
    public static function user(mixed $user): array
    {
        return Account::describe($user);
    }
}
