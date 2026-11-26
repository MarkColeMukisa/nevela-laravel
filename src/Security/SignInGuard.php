<?php

namespace Nevela\Laravel\Security;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Forms\FormGuard;

/**
 * Wrong passwords, counted by address and account together (step 11, part 3).
 *
 * - **One address, one account.** After `captcha_after` wrong passwords, the web sign-in asks
 *   for Turnstile, where the app has it set up. After `lock_after`, that address is locked out
 *   of that account for `lock_minutes`. The owner on another network, and everyone else behind
 *   the same address, sign in as before: five mistakes by one person at an office lock out
 *   nobody else there.
 * - **One account, any address.** After `account_captcha_after` wrong passwords in an hour, the
 *   web sign-in to it asks for Turnstile from everywhere. It is never locked: an account that
 *   could be locked from anywhere could be locked by anyone who knows its email.
 * - **One address, many accounts.** Wrong passwords for many different accounts from one
 *   address is someone trying a list of them: past `stuffing_after` accounts, each further one is
 *   a strike against the address (Security\Addresses), which blocks it for a while.
 *
 * Turnstile is asked only of the web sign-in: a phone can't show it. The phone, and anything
 * else calling the API itself, goes to the lockout at the same count. Without TURNSTILE_SECRET_KEY
 * there is no Turnstile step anywhere, only the lockout.
 *
 * Counted by the email as typed, the same for an address with no account, and answered the
 * same: nothing here says which addresses have accounts.
 */
final class SignInGuard
{
    /** The Turnstile action a sign-in token has to be solved for. */
    public const ACTION = 'sign-in';

    /** A refusal before the password is checked, or null to go on. */
    public static function before(Request $request, string $email): ?JsonResponse
    {
        $pair = self::pair($request, $email);
        if ($pair !== null && ($until = Cache::get('nevela-signin-lock:'.$pair)) !== null) {
            $seconds = max(1, (int) $until - time());

            return response()->json([
                'error' => 'Too many wrong passwords for this account from here. Try again in '.self::inWords($seconds).', sign in another way, or reset the password.',
                'code' => 'SIGN_IN_LOCKED',
                'retryAfter' => $seconds,
            ], 429, ['Retry-After' => (string) $seconds]);
        }

        // 0 switches a rule off.
        $byPair = self::setting('captcha_after', 3);
        $byAccount = self::setting('account_captcha_after', 20);
        $asks = ($byPair > 0 && $pair !== null && RateLimiter::attempts('nevela-signin-pair:'.$pair) >= $byPair)
            || ($byAccount > 0 && RateLimiter::attempts('nevela-signin-account:'.self::hash($email)) >= $byAccount);
        // Only of a web sign-in that says it can show the check: one built without the site key
        // can't, and its people would be stuck. Leaving it out gains a script nothing: it goes
        // on to the lockout, as anything calling the API itself does.
        if (! $asks || ! Account::fromDashboard($request) || ! $request->boolean('canCheck') || FormGuard::robotCheck() !== 'on') {
            return null;
        }
        $token = $request->input('turnstile');
        if (is_string($token) && $token !== '' && FormGuard::solved($request, $token, self::ACTION) === null) {
            return null;
        }

        return response()->json([
            'error' => is_string($token) && $token !== '' ? "The check didn't work. Try it again." : 'Several wrong passwords: show you are a person to try again.',
            'code' => 'CAPTCHA_REQUIRED',
        ], 428);
    }

    /** A wrong password: counted for the address and the account, and toward a lockout. */
    public static function failed(Request $request, string $email): void
    {
        $account = self::hash($email);
        RateLimiter::hit('nevela-signin-account:'.$account, 3600);
        $address = Addresses::of($request);
        $pair = self::pair($request, $email);
        if ($pair !== null) {
            $minutes = self::setting('lock_minutes', 15);
            RateLimiter::hit('nevela-signin-pair:'.$pair, $minutes * 60);
            if (self::setting('lock_after', 10) > 0 && RateLimiter::attempts('nevela-signin-pair:'.$pair) >= self::setting('lock_after', 10)) {
                Cache::put('nevela-signin-lock:'.$pair, time() + $minutes * 60, $minutes * 60);
                RateLimiter::clear('nevela-signin-pair:'.$pair);
            }
        }
        if ($address !== null) {
            // Which accounts this address has got wrong in the last ten minutes: many different
            // ones is a list being tried. Each new account past the limit is a strike; the same one
            // again isn't, so one person's typos never add up to a block.
            $key = 'nevela-signin-accounts:'.$address;
            $window = Cache::get($key);
            if (! is_array($window) || ($window['until'] ?? 0) <= time()) {
                $window = ['until' => time() + 600, 'seen' => []];
            }
            if (! isset($window['seen'][$account])) {
                $window['seen'][$account] = true;
                // A fixed window: ten minutes from the first, not from the latest.
                Cache::put($key, $window, max(1, $window['until'] - time()));
                $limit = self::setting('stuffing_after', 10);
                if ($limit > 0 && count($window['seen']) > $limit) {
                    Addresses::strike($address, 'sign-in');
                }
            }
        }
    }

    /** The right password: this address starts again on this account. */
    public static function succeeded(Request $request, string $email): void
    {
        if (($pair = self::pair($request, $email)) !== null) {
            RateLimiter::clear('nevela-signin-pair:'.$pair);
        }
    }

    /**
     * The password was reset, which only the email's owner can do: this address starts again on
     * this account, and so does the account's count from everywhere.
     */
    public static function reset(Request $request, string $email): void
    {
        RateLimiter::clear('nevela-signin-account:'.self::hash($email));
        if (($pair = self::pair($request, $email)) !== null) {
            RateLimiter::clear('nevela-signin-pair:'.$pair);
            Cache::forget('nevela-signin-lock:'.$pair);
        }
    }

    /** This address and this account, or null when the web app can't say whose address it is. */
    private static function pair(Request $request, string $email): ?string
    {
        $address = Addresses::of($request);

        return $address === null ? null : hash('sha256', $address.'|'.mb_strtolower(trim($email)));
    }

    private static function hash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    private static function setting(string $key, int $default): int
    {
        return max(0, (int) config("nevela.security.sign_in.{$key}", $default));
    }

    private static function inWords(int $seconds): string
    {
        $minutes = (int) ceil($seconds / 60);

        return $minutes <= 1 ? 'a minute' : "{$minutes} minutes";
    }
}
