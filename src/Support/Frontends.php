<?php

namespace Nevela\Laravel\Support;

use Nevela\Laravel\Generator\GeneratedFile;

/**
 * The Next.js apps beside the Laravel app, as the generator writes to them: the web app
 * (nevela.web_path) and, in a project with the triple tier, the staff's admin app
 * (nevela.admin_path). Each only where its folder is there.
 */
final class Frontends
{
    /** @return array<string, string> target => folder */
    public static function roots(): array
    {
        $roots = [];
        $web = config('nevela.web_path');
        if (is_string($web) && $web !== '' && is_dir($web)) {
            $roots[GeneratedFile::TARGET_WEB] = rtrim((string) realpath($web), '/\\');
        }
        // An admin app is a Next.js app: a folder called admin that is something else of the
        // project's own doesn't take the dashboard's pages away from the web app.
        $admin = config('nevela.admin_path');
        if (is_string($admin) && $admin !== '' && self::isNextApp($admin)) {
            $roots[GeneratedFile::TARGET_ADMIN] = rtrim((string) realpath($admin), '/\\');
        }

        return $roots;
    }

    /** Whether a folder holds a Next.js app: a package.json that depends on next. */
    public static function isNextApp(string $folder): bool
    {
        $manifest = json_decode((string) @file_get_contents(rtrim($folder, '/\\').DIRECTORY_SEPARATOR.'package.json'), true);

        return is_array($manifest) && isset($manifest['dependencies']['next']);
    }
}
