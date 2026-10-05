<?php

namespace Nevela\Laravel\Auth;

use Illuminate\Http\Request;

/**
 * Where the dashboard is.
 *
 * Emails link to it, and a passkey is tied to its address, so Laravel has to know it. The
 * dashboard says where it is on each request (the X-Nevela-Origin header), because in
 * development it moves to another port when 3000 is taken. What it says is only believed
 * when it is the configured address, or a localhost address while the app is in
 * development: otherwise anyone could have a sign-in link point at a site of their own.
 */
final class Web
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public static function configured(): string
    {
        return self::originOf((string) config('nevela.auth.web_url', 'http://localhost:3000')) ?? 'http://localhost:3000';
    }

    /** The dashboard's origin for this request: "https://app.example.com", no path. */
    public static function origin(?Request $request = null): string
    {
        $claimed = self::originOf((string) ($request ?? request())->header('X-Nevela-Origin', ''));

        return $claimed !== null && self::allowed($claimed) ? $claimed : self::configured();
    }

    public static function allowed(string $origin): bool
    {
        $origin = self::originOf($origin);
        if ($origin === null) {
            return false;
        }
        if ($origin === self::configured()) {
            return true;
        }

        return app()->environment('local', 'testing') && in_array(parse_url($origin, PHP_URL_HOST), self::LOCAL_HOSTS, true);
    }

    /** A page of the dashboard, e.g. url('/reset-password', ['token' => …]). */
    public static function url(string $path, array $query = [], ?Request $request = null): string
    {
        return self::origin($request).'/'.ltrim($path, '/').($query === [] ? '' : '?'.http_build_query($query));
    }

    /** The host a passkey is bound to: the dashboard's, without scheme or port. */
    public static function host(?Request $request = null): string
    {
        return (string) parse_url(self::origin($request), PHP_URL_HOST);
    }

    /** Only a path on the dashboard, never another site: for "where to go after signing in". */
    public static function path(mixed $value, string $fallback = '/dashboard'): string
    {
        return is_string($value) && str_starts_with($value, '/') && ! str_starts_with($value, '//') && ! str_starts_with($value, '/\\') ? $value : $fallback;
    }

    private static function originOf(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
