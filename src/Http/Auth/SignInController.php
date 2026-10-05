<?php

namespace Nevela\Laravel\Http\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Auth\AuthMail;
use Nevela\Laravel\Auth\Challenges;
use Nevela\Laravel\Auth\Passkeys;
use Nevela\Laravel\Auth\Totp;
use Nevela\Laravel\Auth\Web;
use RuntimeException;

/**
 * Getting signed in: with a password (and a second step when one is set up), a passkey,
 * an emailed link or an emailed code. Each ends the same way, with a Sanctum token.
 *
 * Errors carry a `code` beside the message, so a client can say something more useful
 * than the message, in its own words.
 */
final class SignInController
{
    use Answers;

    /** How long a sign-in waits for its second step, and an emailed code or link lasts. */
    private const SECOND_STEP = 600;

    private const EMAILED = 300;

    /** Which ways of signing in are switched on, for a client drawing the sign-in page. */
    public function config(): JsonResponse
    {
        return response()->json(self::methods());
    }

    /** @return array<string, mixed> */
    public static function methods(): array
    {
        return [
            'registration' => (bool) config('nevela.auth.registration', false),
            'magicLink' => (bool) config('nevela.auth.magic_link', true),
            'emailCode' => (bool) config('nevela.auth.email_code', true),
            'passkeys' => (bool) config('nevela.auth.passkeys', true),
            'twoFactor' => [
                'authenticator' => (bool) config('nevela.auth.two_factor.authenticator', true),
                'email' => (bool) config('nevela.auth.two_factor.email', true),
            ],
            'requireEmailVerification' => (bool) config('nevela.auth.require_email_verification', false),
        ];
    }

    /** POST auth/token: email and password. */
    public function password(Request $request): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'deviceName' => ['nullable', 'string', 'max:255'],
        ]);

        $user = Account::findByEmail($input['email']);
        // A password is checked even when there is no such account, against a hash of
        // nothing: otherwise "no such account" would answer faster than "wrong password",
        // and the difference tells a visitor which addresses have accounts.
        $right = Hash::check($input['password'], $user ? (string) $user->getAuthPassword() : self::decoy());
        if (! $user || ! $right) {
            return $this->refuse('INVALID_EMAIL_OR_PASSWORD', "That email and password don't match.", 401);
        }
        if (config('nevela.auth.require_email_verification', false) && $user->email_verified_at === null) {
            AccountController::sendVerification($user, $request);

            return $this->refuse('EMAIL_NOT_VERIFIED', "Verify your email first: we've sent you a new link.", 403);
        }

        $second = Account::twoFactor($user);
        if ($second?->enabled_at !== null) {
            $methods = array_values(array_filter([
                $second->usesAuthenticator() && config('nevela.auth.two_factor.authenticator', true) ? 'totp' : null,
                config('nevela.auth.two_factor.email', true) ? 'email' : null,
                ($second->backup_codes ?? []) !== [] ? 'backup' : null,
            ]));

            return response()->json([
                'twoFactor' => true,
                'challenge' => Challenges::start('two-factor', ['user' => (string) $user->getAuthIdentifier(), 'device' => $input['deviceName'] ?? null], self::SECOND_STEP),
                'methods' => $methods,
            ]);
        }

        return response()->json(Account::signIn($user, $request), 201);
    }

    /** A password hash that matches nothing, made once per process with the app's own hashing settings. */
    private static function decoy(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(bin2hex(random_bytes(16)));
    }

    /** POST auth/two-factor/send: email a code for a sign-in that is waiting for its second step. */
    public function sendSecondStep(Request $request): JsonResponse
    {
        $this->enabled('two_factor.email');
        $challenge = (string) $request->input('challenge');
        $pending = Challenges::get('two-factor', $challenge);
        $user = $pending ? Account::model()::query()->find($pending['user']) : null;
        if (! $user) {
            return $this->refuse('SESSION_EXPIRED', 'That sign-in has expired. Start again.', 401);
        }

        if (! Challenges::mayIssue('two-factor', (string) $pending['user'])) {
            return $this->refuse('TOO_MANY_ATTEMPTS', 'Too many codes were asked for. Try again in an hour, or use another method.', 429);
        }
        [$code, $hash] = Challenges::code();
        Challenges::update('two-factor', $challenge, ['code' => $hash]);
        AuthMail::send($user->email, 'Your '.AuthMail::app().' sign-in code', ["Your sign-in code is {$code}.", 'It expires in 10 minutes.'], null, $code);

        return response()->json(['sent' => true]);
    }

    /** POST auth/two-factor/verify: finish a password sign-in with a code. */
    public function secondStep(Request $request): JsonResponse
    {
        $input = $request->validate([
            'challenge' => ['required', 'string'],
            'method' => ['required', 'in:totp,email,backup'],
            'code' => ['required', 'string', 'max:64'],
        ]);
        $pending = Challenges::get('two-factor', $input['challenge']);
        $user = $pending ? Account::model()::query()->find($pending['user']) : null;
        $second = $user ? Account::twoFactor($user) : null;
        if (! $user || $second?->enabled_at === null) {
            return $this->refuse('SESSION_EXPIRED', 'That sign-in has expired. Start again.', 401);
        }

        $right = match ($input['method']) {
            'totp' => $second->usesAuthenticator() && Totp::verify((string) $second->secret, $input['code']),
            'email' => isset($pending['code']) && hash_equals($pending['code'], Challenges::hash($input['code'])),
            'backup' => self::spendBackupCode($second, $input['code']),
        };
        if (! $right) {
            return Challenges::miss('two-factor', $input['challenge'])
                ? $this->refuse('INVALID_TWO_FACTOR_CODE', "That code isn't right. Check it and try again.", 401)
                : $this->refuse('TOO_MANY_ATTEMPTS', 'Too many attempts. Sign in again.', 429);
        }

        Challenges::forget('two-factor', $input['challenge']);
        $request->merge(['deviceName' => $pending['device'] ?? null]);

        return response()->json(Account::signIn($user, $request), 201);
    }

    /** A backup code works once. */
    private static function spendBackupCode(mixed $second, string $code): bool
    {
        $hash = Challenges::hash(strtolower($code));
        $codes = $second->backup_codes ?? [];
        foreach ($codes as $index => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$index]);
                $second->forceFill(['backup_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** POST auth/magic-link: email a link that signs the person in. */
    public function sendLink(Request $request): JsonResponse
    {
        $this->enabled('magic_link');
        $input = $request->validate(['email' => ['required', 'email'], 'next' => ['nullable', 'string', 'max:2000']]);

        // The answer is the same whether or not the address has an account.
        if ($user = Account::findByEmail($input['email'])) {
            $token = Challenges::start('magic-link', ['user' => (string) $user->getAuthIdentifier()], self::EMAILED);
            $url = Web::url('/api/auth/magic-link', ['token' => $token, 'next' => Web::path($input['next'] ?? null)], $request);
            AuthMail::send($user->email, 'Sign in to '.AuthMail::app(), ['Use this link to sign in. It works once and expires in 5 minutes.'], ['Sign in', $url], $url);
        }

        return response()->json(['sent' => true]);
    }

    /** POST auth/magic-link/verify */
    public function link(Request $request): JsonResponse
    {
        $this->enabled('magic_link');
        $pending = Challenges::take('magic-link', (string) $request->input('token'));
        $user = $pending ? Account::model()::query()->find($pending['user']) : null;
        if (! $user) {
            return $this->refuse('INVALID_TOKEN', 'That sign-in link has expired or was already used. Ask for a new one.', 401);
        }
        // Opening a link sent to the address proves the address.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return response()->json(Account::signIn($user, $request), 201);
    }

    /** POST auth/email-code: email a six-digit sign-in code. */
    public function sendCode(Request $request): JsonResponse
    {
        $this->enabled('email_code');
        $input = $request->validate(['email' => ['required', 'email']]);

        if (($user = Account::findByEmail($input['email'])) && Challenges::mayIssue('email-code', mb_strtolower($user->email))) {
            [$code, $hash] = Challenges::code();
            Challenges::put('email-code', mb_strtolower($user->email), ['user' => (string) $user->getAuthIdentifier(), 'code' => $hash], self::EMAILED);
            AuthMail::send($user->email, 'Your '.AuthMail::app().' sign-in code', ["Your sign-in code is {$code}.", 'It expires in 5 minutes.'], null, $code);
        }

        return response()->json(['sent' => true]);
    }

    /** POST auth/email-code/verify */
    public function code(Request $request): JsonResponse
    {
        $this->enabled('email_code');
        $input = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'string', 'max:16']]);
        $id = mb_strtolower(trim($input['email']));
        $pending = Challenges::get('email-code', $id);
        if ($pending === null) {
            return $this->refuse('OTP_EXPIRED', 'That code has expired. Ask for a new one.', 401);
        }
        if (! hash_equals($pending['code'], Challenges::hash($input['code']))) {
            return Challenges::miss('email-code', $id)
                ? $this->refuse('INVALID_OTP', "That code isn't right. Check it and try again.", 401)
                : $this->refuse('TOO_MANY_ATTEMPTS', 'Too many attempts. Ask for a new code.', 429);
        }
        Challenges::forget('email-code', $id);
        $user = Account::model()::query()->find($pending['user']);
        if (! $user) {
            return $this->refuse('OTP_EXPIRED', 'That code has expired. Ask for a new one.', 401);
        }
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return response()->json(Account::signIn($user, $request), 201);
    }

    /** POST auth/passkey/options: what the browser needs to ask the device for a passkey. */
    public function passkeyOptions(Request $request): JsonResponse
    {
        $this->enabled('passkeys');

        return response()->json(Passkeys::requestOptions($request));
    }

    /** POST auth/passkey: sign in with the passkey the device answered with. */
    public function passkey(Request $request): JsonResponse
    {
        $this->enabled('passkeys');
        $input = $request->validate(['challenge' => ['required', 'string'], 'response' => ['required', 'array']]);
        try {
            $passkey = Passkeys::verify($input['challenge'], $input['response'], $request);
        } catch (RuntimeException $e) {
            return $this->refuse('PASSKEY_FAILED', $e->getMessage(), 401);
        }
        $user = Account::model()::query()->find($passkey->user_id);
        if (! $user) {
            return $this->refuse('PASSKEY_FAILED', "This passkey's account no longer exists.", 401);
        }

        return response()->json(Account::signIn($user, $request), 201);
    }
}
