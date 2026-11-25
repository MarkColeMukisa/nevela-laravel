<?php

namespace Nevela\Laravel\Auth;

use Illuminate\Http\Request;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GoogleProvider;

/**
 * Signing in with Google or GitHub, through Laravel Socialite.
 *
 * One switch turns it on or off for the whole app (SOCIAL_AUTH_ENABLED), and a provider
 * is offered once its two keys are set. Off, no button shows and the routes refuse.
 *
 * The browser never talks to Laravel here. The dashboard's server asks for the provider's
 * address, and the provider sends the browser back to the dashboard, whose server hands
 * the code to Laravel. So the address registered with the provider is the dashboard's:
 *
 *     {NEVELA_WEB_URL}/api/auth/oauth/{provider}/callback
 *
 * With an admin app beside the public site (NEVELA_ADMIN_URL), register its address as well:
 * each app's sign-in comes back to the app it began on.
 */
final class Social
{
    /** The providers Nevela knows, and Socialite's class for each. */
    public const PROVIDERS = [
        'google' => GoogleProvider::class,
        'github' => GithubProvider::class,
    ];

    public const LABELS = ['google' => 'Google', 'github' => 'GitHub'];

    /**
     * Stands in for the provider in tests, which have no Google to ask: given the provider
     * and the code, the person it would have answered with.
     *
     * @var (callable(string, string): ProviderUser)|null
     */
    public static $fake = null;

    /** Whether social sign-in is switched on at all. */
    public static function enabled(): bool
    {
        return (bool) config('nevela.auth.social.enabled', false);
    }

    /**
     * The providers people can use: switched on, and with both keys.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        if (! self::enabled()) {
            return [];
        }

        return array_values(array_filter(array_keys(self::PROVIDERS), fn (string $provider) => self::configured($provider)));
    }

    /** Whether a provider has its keys, whether or not the switch is on. */
    public static function configured(string $provider): bool
    {
        $keys = (array) config("nevela.auth.social.{$provider}", []);

        return isset(self::PROVIDERS[$provider]) && (string) ($keys['client_id'] ?? '') !== '' && (string) ($keys['client_secret'] ?? '') !== '';
    }

    /** Where the provider sends the browser back to: a page of the dashboard. */
    public static function callback(string $provider, ?Request $request = null): string
    {
        return Web::url("/api/auth/oauth/{$provider}/callback", [], $request);
    }

    /**
     * Where the provider sends the browser back to when a mobile app started the sign-in:
     * this API's own address, which hands the browser on to the app. It is APP_URL, so it is
     * the same address each time, and the one to register with the provider.
     */
    public static function appCallback(string $provider): string
    {
        return rtrim((string) config('app.url'), '/').'/'.trim((string) config('nevela.prefix', 'api'), '/')."/auth/oauth/{$provider}/app";
    }

    /**
     * An address an app may be sent back to, or null. Only one that starts with an address the
     * app's owner listed (NEVELA_APP_REDIRECTS), such as "juice-me://": anything else would let
     * whoever starts a sign-in send the code wherever they like. The list's entry has to end at
     * a boundary, so "https://example.com" doesn't allow "https://example.com.evil.test".
     * While APP_ENV is local, Expo Go's own address is allowed as well.
     */
    public static function appRedirect(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 500 || preg_match('/[\x00-\x20\x7F\\\\#]/', $value) === 1) {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) !== 1) {
            return null;
        }
        $allowed = array_map(fn ($entry) => trim((string) $entry), (array) config('nevela.auth.social.app_redirects', []));
        if (app()->environment('local')) {
            array_push($allowed, 'exp://', 'exps://');
        }
        foreach ($allowed as $prefix) {
            if ($prefix === '' || ! str_starts_with(strtolower($value), strtolower($prefix))) {
                continue;
            }
            $next = substr($value, strlen($prefix), 1);
            if (str_ends_with($prefix, '/') || $next === '' || in_array($next, ['/', '?'], true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * What an app sends when it starts a sign-in to prove, when it finishes, that it is the one
     * that started it: the SHA-256 of a secret it keeps, in base64url. The browser only ever
     * carries the code and the state, so whoever intercepts them still can't finish the sign-in.
     */
    public static function appChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /** Socialite's provider, set up from config/nevela.php, with no session: the state is ours. */
    public static function driver(string $provider, string $callback): AbstractProvider
    {
        $keys = (array) config("nevela.auth.social.{$provider}", []);

        /** @var AbstractProvider $driver */
        $driver = Socialite::buildProvider(self::PROVIDERS[$provider], [
            'client_id' => (string) ($keys['client_id'] ?? ''),
            'client_secret' => (string) ($keys['client_secret'] ?? ''),
            'redirect' => $callback,
        ]);

        return $driver->stateless();
    }

    /** The address to send the browser to, carrying our state. */
    public static function authorizationUrl(string $provider, string $callback, string $state): string
    {
        return self::driver($provider, $callback)->with(['state' => $state])->redirect()->getTargetUrl();
    }

    /**
     * Who the provider says this is, from the code it sent back.
     *
     * @return array{id: string, email: string|null, verified: bool, name: string|null}
     */
    public static function profile(string $provider, string $callback, string $code): array
    {
        $user = self::$fake !== null ? (self::$fake)($provider, $code) : self::driver($provider, $callback)->user();
        $raw = method_exists($user, 'getRaw') ? (array) $user->getRaw() : [];
        $email = $user->getEmail();

        return [
            'id' => (string) $user->getId(),
            'email' => is_string($email) && $email !== '' ? mb_strtolower(trim($email)) : null,
            // Google says, of each address. GitHub only gives an address that is verified:
            // the public one has to be, and Socialite otherwise asks for the primary verified one.
            'verified' => match ($provider) {
                'google' => ($raw['email_verified'] ?? $raw['verified_email'] ?? false) === true,
                default => is_string($email) && $email !== '',
            },
            'name' => $user->getName() ?: $user->getNickname(),
        ];
    }
}
