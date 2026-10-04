<?php

namespace Nevela\Laravel\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

/** What Packagist knows about nevela/laravel. */
final class Releases
{
    private static string|null|false $latest = false;

    /** The newest stable release, or null when Packagist can't be reached. Asked once per run. */
    public static function latest(): ?string
    {
        if (self::$latest !== false) {
            return self::$latest;
        }
        try {
            $releases = Http::timeout(8)->get('https://repo.packagist.org/p2/nevela/laravel.json')->json('packages.nevela/laravel') ?? [];
        } catch (Throwable) {
            return self::$latest = null;
        }

        return self::$latest = self::newest(array_map(fn ($release) => (string) ($release['version'] ?? ''), $releases));
    }

    /**
     * The highest x.y.z in a list of version names. Branches and pre-releases don't count.
     *
     * @param  list<string>  $names
     */
    public static function newest(array $names): ?string
    {
        $versions = [];
        foreach ($names as $name) {
            if (preg_match('/^v?(\d+\.\d+\.\d+)$/', $name, $m)) {
                $versions[] = $m[1];
            }
        }
        usort($versions, 'version_compare');

        return $versions === [] ? null : end($versions);
    }
}
