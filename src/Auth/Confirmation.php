<?php

namespace Nevela\Laravel\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Nevela\Laravel\Http\TokenController;

/**
 * Confirming it's you again, before something that matters: a resource action marked
 * `sensitive` (a transfer, a refund), and, where the app asks for it, changing who can sign
 * in (people, roles, deleted accounts, blocked emails).
 *
 * Such a request answers 428 until it is confirmed: with the password, an emailed code, the
 * authenticator app or a passkey, at POST auth/confirm. The answer carries a challenge for
 * that, short-lived and kept here, so it can't be made up or used twice.
 *
 * - A sensitive action is confirmed each time. The confirmation is a token for that one
 *   request, by the same person on the same sign-in, with the same body: a token for
 *   "refund 10" doesn't refund 10,000. It is sent back in X-Nevela-Confirmation, and works once.
 * - Changing who can sign in opens a window instead: once confirmed, that sign-in may do it
 *   for a few minutes without being asked again, so an administrator going through ten
 *   people confirms once.
 *
 * Asked for after the policy and the validation, never before: nobody is asked to confirm a
 * request that would be refused anyway. Which dashboard or phone asks doesn't matter: the
 * API asks, so a script can't skip what the dashboard's dialog asks.
 */
final class Confirmation
{
    public const HEADER = 'X-Nevela-Confirmation';

    /** How long the challenge, and then the confirmation, last. */
    public const SECONDS = 300;

    /** The ways to confirm, for a person: their password and an emailed code always, the authenticator app and a passkey where they have them. */
    public const METHODS = ['password', 'email', 'totp', 'passkey'];

    /** Whether changing who can sign in asks for a confirmation (nevela.auth.confirm.access). */
    public static function access(): bool
    {
        return filter_var(config('nevela.auth.confirm.access', false), FILTER_VALIDATE_BOOLEAN);
    }

    /** Minutes a confirmation for changing who can sign in lasts on that sign-in. */
    public static function windowSeconds(): int
    {
        return max(60, (int) config('nevela.auth.confirm.window', 5) * 60);
    }

    /**
     * Go on only if this request is confirmed, each time: a sensitive action. Otherwise answer 428.
     */
    public static function requireForRequest(Request $request): void
    {
        $user = $request->user();
        $print = self::fingerprint($request);
        $given = (string) $request->header(self::HEADER, '');
        if ($given !== '') {
            $token = Challenges::get('confirmed', $given);
            if ($token !== null && hash_equals($token['print'], $print)) {
                Challenges::forget('confirmed', $given);

                return;
            }
        }

        throw self::ask($user, ['kind' => 'request', 'print' => $print]);
    }

    /**
     * Go on only if this sign-in confirmed in the last few minutes: changing who can sign in,
     * where the app asks for it. Otherwise answer 428.
     */
    public static function requireForAccess(Request $request): void
    {
        if (! self::access()) {
            return;
        }
        $user = $request->user();
        if (Challenges::get('confirmed-window', self::signInOf($request)) !== null) {
            return;
        }

        throw self::ask($user, ['kind' => 'window', 'sign_in' => self::signInOf($request)]);
    }

    /** The 428, with a challenge to confirm with. */
    private static function ask(mixed $user, array $what): HttpResponseException
    {
        $request = request();
        $challenge = Challenges::start('confirm', $what + [
            'user' => (string) $user->getAuthIdentifier(),
            'sign_in' => self::signInOf($request),
        ], self::SECONDS);

        return new HttpResponseException(response()->json([
            'error' => "Confirm it's you: this needs your password again, or a code.",
            'code' => 'CONFIRMATION_REQUIRED',
            'challenge' => $challenge,
            'methods' => self::methodsFor($user),
            'window' => $what['kind'] === 'window',
        ], 428));
    }

    /**
     * What a confirmed challenge gives: for a window, nothing to send back (the sign-in is
     * confirmed for a few minutes); for one request, a token to send back with it.
     *
     * @param  array<string, mixed>  $challenge
     * @return array{confirmed: true, confirmation?: string, expiresAt: string}
     */
    public static function grant(array $challenge, Request $request): array
    {
        if (($challenge['kind'] ?? null) === 'window') {
            $seconds = self::windowSeconds();
            Challenges::put('confirmed-window', (string) $challenge['sign_in'], ['user' => $challenge['user']], $seconds);

            return ['confirmed' => true, 'expiresAt' => now()->addSeconds($seconds)->toAtomString()];
        }
        $token = Challenges::start('confirmed', ['print' => (string) $challenge['print']], self::SECONDS);

        return ['confirmed' => true, 'confirmation' => $token, 'expiresAt' => now()->addSeconds(self::SECONDS)->toAtomString()];
    }

    /** @return list<string> */
    public static function methodsFor(mixed $user): array
    {
        $methods = ['password', 'email'];
        $second = Account::twoFactor($user);
        if ($second?->enabled_at !== null && $second->usesAuthenticator()) {
            $methods[] = 'totp';
        }
        if ((bool) config('nevela.auth.passkeys', true) && Web::exists() && \Nevela\Laravel\Models\Passkey::query()->where('user_id', (string) $user->getAuthIdentifier())->exists()) {
            $methods[] = 'passkey';
        }

        return $methods;
    }

    /** This sign-in: its session, or for a token from before sessions, the token. */
    public static function signInOf(Request $request): string
    {
        $user = $request->user();

        return (string) $user->getAuthIdentifier().':'.(TokenController::deviceOf($user->currentAccessToken()) ?? 'none');
    }

    /**
     * The request as confirmed: whose, on which sign-in, which route, and exactly what it sends.
     */
    public static function fingerprint(Request $request): string
    {
        $body = $request->json()->all() ?: $request->except([]);

        return hash('sha256', json_encode([
            self::signInOf($request),
            strtoupper($request->method()),
            '/'.ltrim($request->path(), '/'),
            self::canonical($body),
        ]) ?: '');
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
