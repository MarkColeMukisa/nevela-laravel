<?php

namespace Nevela\Laravel\Forms;

/**
 * Domains that hand out addresses to be thrown away: mailinator.com and its many kin.
 *
 * An address there is real for ten minutes, which is long enough to pass every other
 * check and no use for writing back. The list ships with the package (the public domain
 * list kept at github.com/disposable-email-domains), and `php artisan nevela:disposable`
 * fetches the current one into the app's storage, where it is read from in preference.
 */
final class Disposable
{
    public const SOURCE = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/main/disposable_email_blocklist.conf';

    /** @var array<string, true>|null */
    private static ?array $domains = null;

    /** Whether a domain, or one it is a subdomain of, is on the list. */
    public static function is(string $domain): bool
    {
        $domain = strtolower(trim($domain, " .\t\n\r"));
        $list = self::domains();
        // mail.mailinator.com is mailinator.com's too.
        while ($domain !== '' && str_contains($domain, '.')) {
            if (isset($list[$domain])) {
                return true;
            }
            $domain = substr($domain, (int) strpos($domain, '.') + 1);
        }

        return false;
    }

    /** Where a refreshed list is kept, in the app's own storage. */
    public static function refreshedPath(): string
    {
        return storage_path('app/nevela/disposable-domains.txt');
    }

    public static function count(): int
    {
        return count(self::domains());
    }

    public static function forget(): void
    {
        self::$domains = null;
    }

    /** @return array<string, true> */
    private static function domains(): array
    {
        if (self::$domains !== null) {
            return self::$domains;
        }
        try {
            $refreshed = self::refreshedPath();
        } catch (\Throwable) {
            $refreshed = null; // no app to have a storage folder: the list is read on its own in a test
        }
        $file = $refreshed !== null && is_file($refreshed) ? $refreshed : dirname(__DIR__, 2).'/resources/disposable-domains.txt';
        $domains = [];
        foreach (@file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = strtolower(trim($line));
            if ($line !== '' && $line[0] !== '#') {
                $domains[$line] = true;
            }
        }

        return self::$domains = $domains;
    }
}
