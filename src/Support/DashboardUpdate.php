<?php

namespace Nevela\Laravel\Support;

/**
 * Works out how to bring an app's dashboard up to a newer template without losing what
 * the developer changed.
 *
 * Three versions of every file are compared, by fingerprint: the template the app is on
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
     * A file's fingerprint. Line endings are ignored: git on Windows may have rewritten
     * them, and that is not a change anyone made.
     */
    public static function hash(string $contents): string
    {
        return sha1(str_replace("\r\n", "\n", $contents));
    }

    /**
     * @param  array<string, string>  $contents  path => contents
     * @return array<string, string> path => fingerprint
     */
    public static function hashes(array $contents): array
    {
        return array_map(self::hash(...), $contents);
    }

    /**
     * @param  array<string, string>  $base  path => fingerprint, the template the app is on
     * @param  array<string, string>  $next  path => fingerprint, the template to move to
     * @param  array<string, string|null>  $yours  path => fingerprint of the app's file, null when it is missing
     * @return array<string, string> path => one of the constants above. Files needing nothing are left out.
     */
    public static function plan(array $base, array $next, array $yours): array
    {
        $plan = [];
        foreach ($next as $path => $wanted) {
            $mine = $yours[$path] ?? null;
            $before = $base[$path] ?? null;

            if ($mine === $wanted) {
                continue; // already there
            }
            if ($mine === null) {
                // Not in the app. Add it if it is new; leave it out if you deleted it.
                if ($before === null) {
                    $plan[$path] = self::ADD;
                }

                continue;
            }
            if ($before === $wanted) {
                continue; // the template didn't change this file; your version stands
            }
            $plan[$path] = $mine === $before ? self::UPDATE : self::CONFLICT;
        }

        foreach ($base as $path => $before) {
            if (! isset($next[$path]) && ($yours[$path] ?? null) === $before) {
                $plan[$path] = self::REMOVED;
            }
        }

        ksort($plan);

        return $plan;
    }

    /**
     * The files of the template that differ in the app: what the developer has made their own.
     *
     * @param  array<string, string>  $base  path => fingerprint, the template the app is on
     * @param  array<string, string|null>  $yours  path => fingerprint of the app's file, null when it is missing
     * @return array{changed: list<string>, deleted: list<string>}
     */
    public static function changes(array $base, array $yours): array
    {
        $changed = [];
        $deleted = [];
        foreach ($base as $path => $before) {
            $mine = $yours[$path] ?? null;
            if ($mine === null) {
                $deleted[] = $path;
            } elseif ($mine !== $before) {
                $changed[] = $path;
            }
        }
        sort($changed);
        sort($deleted);

        return ['changed' => $changed, 'deleted' => $deleted];
    }

    /**
     * Move the app's package.json to the template's dependency versions, keeping its name
     * and anything else the developer added.
     *
     * A dependency is moved when the app still has the version the old template had, or
     * doesn't have it at all. One the developer pinned differently is left and reported.
     * Without the old template (`$base` null) only missing dependencies are added.
     *
     * @return array{json: string, changed: array<string, string>, kept: array<string, string>}
     */
    public static function mergePackageJson(string $yours, ?string $base, string $next): array
    {
        $app = json_decode($yours, true, 512, JSON_THROW_ON_ERROR);
        $old = $base === null ? null : json_decode($base, true, 512, JSON_THROW_ON_ERROR);
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
                if ($mine === null || ($old !== null && $mine === $before)) {
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
}
