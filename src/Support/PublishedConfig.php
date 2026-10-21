<?php

namespace Nevela\Laravel\Support;

/**
 * An app's own config/nevela.php against the package's.
 *
 * Laravel's mergeConfigFrom() is one level deep: a section the app has, such as `auth`,
 * replaces the package's whole section. A file published before 0.12 has an `auth` with
 * no `session` in it, and its sign-ins never ended. Here a section is merged key by key,
 * so a setting the app doesn't mention keeps the package's value.
 *
 * Some values are one setting, not a section, and the app's replaces the package's whole:
 * a list (`rich_text.tags`, `middleware`), an allowlist that must never grow behind the
 * app's back (`rich_text.attributes`) and the app's own permissions. Image profiles are a
 * collection: the app's are added to the package's, and each one is the app's whole.
 */
final class PublishedConfig
{
    /** Keyed values taken whole. */
    private const WHOLE = ['permissions', 'rich_text.attributes'];

    /** Named things, not settings: one the app's file leaves out still exists, and isn't missing. */
    private const COLLECTIONS = ['uploads.profiles'];

    /**
     * The release each setting arrived in, so an app can be told what its older file is
     * missing. Add a line here with each new setting in config/nevela.php.
     */
    public const ADDED = [
        'root_path' => '0.1.2',
        'uploads' => '0.2.0',
        'auth.registration' => '0.4.0',
        'auth.magic_link' => '0.4.0',
        'auth.email_code' => '0.4.0',
        'auth.passkeys' => '0.4.0',
        'auth.two_factor' => '0.4.0',
        'auth.require_email_verification' => '0.4.0',
        'auth.check_breached_passwords' => '0.4.0',
        'auth.attempts_per_address' => '0.4.0',
        'auth.default_role' => '0.4.0',
        'auth.web_url' => '0.4.0',
        'auth.proxy_secret' => '0.4.0',
        'auth.issuer' => '0.4.0',
        'permissions' => '0.5.0',
        'bulk_max' => '0.5.2',
        'trash' => '0.6.0',
        'auth.close_account' => '0.7.0',
        'rich_text' => '0.8.0',
        'uploads.private_disk' => '0.8.0',
        'public' => '0.9.0',
        'public.turnstile.hostnames' => '0.9.1',
        'auth.social' => '0.10.0',
        'currency' => '0.11.0',
        'auth.session' => '0.12.0',
    ];

    /**
     * The package's config with the app's laid over it, key by key.
     *
     * @param  array<array-key, mixed>  $package
     * @param  array<array-key, mixed>  $app
     * @return array<array-key, mixed>
     */
    public static function merge(array $package, array $app, string $path = ''): array
    {
        foreach ($app as $key => $value) {
            $at = $path === '' ? (string) $key : "{$path}.{$key}";
            $package[$key] = is_array($value) && array_key_exists($key, $package) && self::isSection($path, $at, $package[$key], $value)
                ? self::merge($package[$key], $value, $at)
                : $value;
        }

        return $package;
    }

    /**
     * The package's settings the app's file doesn't have, by dotted key, with the release
     * that added each where it is known. A missing section is named once, not key by key.
     *
     * @param  array<array-key, mixed>  $package
     * @param  array<array-key, mixed>  $app
     * @return array<string, string|null>
     */
    public static function missing(array $package, array $app, string $path = ''): array
    {
        if (in_array($path, self::COLLECTIONS, true)) {
            return [];
        }
        $missing = [];
        foreach ($package as $key => $value) {
            $at = $path === '' ? (string) $key : "{$path}.{$key}";
            if (! array_key_exists($key, $app)) {
                $missing[$at] = self::ADDED[$at] ?? null;
            } elseif (is_array($app[$key]) && self::isSection($path, $at, $value, $app[$key])) {
                $missing += self::missing($value, $app[$key], $at);
            }
        }

        return $missing;
    }

    /**
     * What the app's published config/nevela.php is missing, or nothing when it has none.
     *
     * @return array<string, string|null>
     */
    public static function missingFromApp(): array
    {
        $file = config_path('nevela.php');
        $app = is_file($file) ? require $file : null;

        return is_array($app) ? self::missing(require __DIR__.'/../../config/nevela.php', $app) : [];
    }

    /** "auth.session (new in 0.12.0) and auth.social (new in 0.10.0)" */
    public static function describe(array $missing): string
    {
        $names = [];
        foreach ($missing as $key => $version) {
            $names[] = $version === null ? $key : "{$key} (new in {$version})";
        }
        $last = array_pop($names);

        return $names === [] ? (string) $last : implode(', ', $names).' and '.$last;
    }

    /** Whether the package's value at this key is a section to merge into, not one setting. */
    private static function isSection(string $parent, string $at, mixed $package, array $app): bool
    {
        return is_array($package) && ! array_is_list($package) && ($app === [] || ! array_is_list($app))
            && ! in_array($at, self::WHOLE, true) && ! in_array($parent, self::COLLECTIONS, true);
    }
}
