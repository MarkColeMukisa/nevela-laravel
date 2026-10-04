<?php

namespace Nevela\Laravel\Support;

/**
 * Works out how to bring an app's dashboard up to a newer template without losing what
 * the developer changed.
 *
 * Three versions of every file are compared: the template the app was created from
 * (base), the newer template (next) and what is in the app now (yours). Only a file the
 * developer hasn't touched is replaced. Pure on purpose: no filesystem, no network.
 */
final class DashboardUpdate
{
    public const UPDATE = 'update';      // you didn't change it; the template did
    public const ADD = 'add';            // new in the template
    public const CONFLICT = 'conflict';  // you changed it and so did the template
    public const REMOVED = 'removed';    // gone from the template; you still have it unchanged

    /**
     * @param  array<string, string>  $base  path => contents, the template the app started from
     * @param  array<string, string>  $next  path => contents, the template to move to
     * @param  array<string, string|null>  $yours  path => contents in the app, null when the file is missing
     * @return array<string, string> path => one of the constants above. Files needing nothing are left out.
     */
    public static function plan(array $base, array $next, array $yours): array
    {
        $plan = [];
        foreach ($next as $path => $contents) {
            $mine = $yours[$path] ?? null;
            $before = $base[$path] ?? null;

            if ($mine !== null && self::same($mine, $contents)) {
                continue; // already there
            }
            if ($mine === null) {
                // Not in the app. Add it if it is new; leave it out if you deleted it.
                if ($before === null) {
                    $plan[$path] = self::ADD;
                }

                continue;
            }
            if ($before !== null && self::same($before, $contents)) {
                continue; // the template didn't change this file; your version stands
            }
            $plan[$path] = $before !== null && self::same($mine, $before) ? self::UPDATE : self::CONFLICT;
        }

        foreach ($base as $path => $contents) {
            $mine = $yours[$path] ?? null;
            if (! isset($next[$path]) && $mine !== null && self::same($mine, $contents)) {
                $plan[$path] = self::REMOVED;
            }
        }

        ksort($plan);

        return $plan;
    }

    /**
     * Move the app's package.json to the template's dependency versions, keeping its name
     * and anything else the developer added.
     *
     * A dependency is moved when the app still has the version the old template had, or
     * doesn't have it at all. One the developer pinned differently is left and reported.
     *
     * @return array{json: string, changed: array<string, string>, kept: array<string, string>}
     */
    public static function mergePackageJson(string $yours, string $base, string $next): array
    {
        $app = json_decode($yours, true, 512, JSON_THROW_ON_ERROR);
        $old = json_decode($base, true, 512, JSON_THROW_ON_ERROR);
        $new = json_decode($next, true, 512, JSON_THROW_ON_ERROR);
        $changed = [];
        $kept = [];

        foreach (['dependencies', 'devDependencies'] as $section) {
            foreach ($new[$section] ?? [] as $name => $version) {
                $mine = $app[$section][$name] ?? null;
                if ($mine === $version) {
                    continue;
                }
                $before = $old[$section][$name] ?? null;
                if ($mine === null || $mine === $before) {
                    $app[$section][$name] = $version;
                    $changed[$name] = ($mine ?? 'not installed').' → '.$version;
                } else {
                    $kept[$name] = "{$mine} (template: {$version})";
                }
            }
            if (isset($app[$section])) {
                ksort($app[$section]);
            }
        }

        // npm's own formatting: two spaces, unescaped slashes, a trailing newline.
        $json = json_encode($app, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $json = preg_replace_callback('/^( {4})+/m', fn ($m) => str_repeat('  ', strlen($m[0]) / 4), $json);

        return ['json' => $json."\n", 'changed' => $changed, 'kept' => $kept];
    }

    /** Equal apart from line endings: git on Windows may have rewritten them. */
    public static function same(string $a, string $b): bool
    {
        return $a === $b || str_replace("\r\n", "\n", $a) === str_replace("\r\n", "\n", $b);
    }
}
