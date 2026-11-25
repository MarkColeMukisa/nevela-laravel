<?php

namespace Nevela\Laravel\Http\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Auth\AuthMail;
use Nevela\Laravel\Auth\Challenges;
use Nevela\Laravel\Auth\Confirmation;
use Nevela\Laravel\Auth\Passkeys;
use RuntimeException;

/**
 * Confirming it's you, for a request that answered 428 (see Auth\Confirmation).
 *
 *   POST auth/confirm                   { challenge, method, password | code | passkey }  → { confirmed, confirmation?, expiresAt }
 *   POST auth/confirm/send              { challenge }    email a code for it
 *   POST auth/confirm/passkey/options   { challenge }    → { passkeyChallenge, options }
 *
 * Only for the person the challenge was made for, on the sign-in it was made on. A wrong
 * password or code counts against the challenge, which goes after five.
 */
final class ConfirmController
{
    use Answers;

    public function confirm(Request $request): JsonResponse
    {
        $input = $request->validate([
            'challenge' => ['required', 'string', 'max:100'],
            'method' => ['required', 'in:'.implode(',', Confirmation::METHODS)],
            'password' => ['nullable', 'string', 'max:500'],
            'code' => ['nullable', 'string', 'max:20'],
            'passkeyChallenge' => ['nullable', 'string', 'max:100'],
            'passkey' => ['nullable', 'array'],
        ]);
        $user = $request->user();
        $challenge = $this->challengeFor($request, $input['challenge']);
        if ($challenge === null) {
            return $this->refuse('CONFIRMATION_EXPIRED', 'That took too long. Try what you were doing again.', 410);
        }
        if (! in_array($input['method'], Confirmation::methodsFor($user), true)) {
            return $this->refuse('METHOD_NOT_ALLOWED', "That isn't a way to confirm for this account.", 422);
        }

        $right = match ($input['method']) {
            'password' => is_string($input['password'] ?? null) && Hash::check($input['password'], (string) $user->getAuthPassword()),
            'email' => $this->emailedCode($input['challenge'], (string) ($input['code'] ?? '')),
            'totp' => (bool) Account::twoFactor($user)?->spendCode((string) ($input['code'] ?? '')),
            'passkey' => $this->passkey($request, $user),
        };
        if (! $right) {
            if (! Challenges::miss('confirm', $input['challenge'])) {
                return $this->refuse('TOO_MANY_ATTEMPTS', 'Too many tries. Start what you were doing again.', 429);
            }

            return $this->refuse('NOT_CONFIRMED', match ($input['method']) {
                'password' => "That isn't your password.",
                'passkey' => "That passkey didn't confirm it. Try again, or use your password.",
                default => "That code isn't right. Check it and try again.",
            }, 422);
        }

        // Once: the same challenge doesn't confirm twice.
        Challenges::forget('confirm', $input['challenge']);
        Challenges::forget('confirm-code', $input['challenge']);

        return response()->json(Confirmation::grant($challenge, $request));
    }

    /** Email a 6-digit code to confirm with: for an account that never chose a password, or forgot it. */
    public function send(Request $request): JsonResponse
    {
        $input = $request->validate(['challenge' => ['required', 'string', 'max:100']]);
        $user = $request->user();
        if ($this->challengeFor($request, $input['challenge']) === null) {
            return $this->refuse('CONFIRMATION_EXPIRED', 'That took too long. Try what you were doing again.', 410);
        }
        if (! Challenges::mayIssue('confirm-code', mb_strtolower((string) $user->email))) {
            return $this->refuse('TOO_MANY_CODES', 'Several codes were sent already. Use the newest, or try again in an hour.', 429);
        }
        [$code, $hash] = Challenges::code();
        Challenges::put('confirm-code', $input['challenge'], ['code' => $hash], Confirmation::SECONDS);
        AuthMail::send((string) $user->email, 'Your '.AuthMail::app().' confirmation code', ["Your code to confirm it's you is {$code}.", 'It expires in 5 minutes. If you weren\'t doing anything just now, change your password.'], null, $code);

        return response()->json(['sent' => true]);
    }

    /** What the browser needs to confirm with a passkey. */
    public function passkeyOptions(Request $request): JsonResponse
    {
        $input = $request->validate(['challenge' => ['required', 'string', 'max:100']]);
        if ($this->challengeFor($request, $input['challenge']) === null) {
            return $this->refuse('CONFIRMATION_EXPIRED', 'That took too long. Try what you were doing again.', 410);
        }
        $options = Passkeys::requestOptions($request);

        return response()->json(['passkeyChallenge' => $options['challenge'], 'options' => $options['options']]);
    }

    /** The challenge, if it was made for this person on this sign-in. */
    private function challengeFor(Request $request, string $id): ?array
    {
        $challenge = Challenges::get('confirm', $id);
        if ($challenge === null || $challenge['user'] !== (string) $request->user()->getAuthIdentifier() || $challenge['sign_in'] !== Confirmation::signInOf($request)) {
            return null;
        }

        return $challenge;
    }

    private function emailedCode(string $challenge, string $code): bool
    {
        $sent = Challenges::get('confirm-code', $challenge);

        return $sent !== null && $code !== '' && hash_equals($sent['code'], Challenges::hash($code));
    }

    private function passkey(Request $request, mixed $user): bool
    {
        try {
            $passkey = Passkeys::verify((string) $request->input('passkeyChallenge'), (array) $request->input('passkey', []), $request);
        } catch (RuntimeException) {
            return false;
        }

        return hash_equals((string) $passkey->user_id, (string) $user->getAuthIdentifier());
    }
}
