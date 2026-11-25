<?php

namespace Nevela\Laravel\Generator;

/**
 * One file the generator wants to exist.
 *
 * - MODE_BLOCK: the file carries a `nevela:generated:start … end` block. Regeneration
 *   rewrites only that block; everything outside it is yours. If you edited inside the
 *   block, regeneration skips the file (the hash no longer matches) unless forced.
 * - MODE_ONCE: written when missing, never touched again (migrations, policies).
 */
final class GeneratedFile
{
    public const MODE_BLOCK = 'block';

    public const MODE_ONCE = 'once';

    public const TARGET_API = 'api';

    public const TARGET_WEB = 'web';

    /** The top of the project, above the Laravel app and the dashboard. */
    public const TARGET_ROOT = 'root';

    /**
     * The staff's admin app, in a project that has one beside the public site. Files are
     * written for TARGET_WEB, and the Writer sends each where it belongs (frontends()).
     */
    public const TARGET_ADMIN = 'admin';

    /**
     * Which of the two Next.js apps a web file belongs in, when the project has both: the
     * dashboard's pages in the admin; the descriptors and the sign-in settings in both, as
     * both sign people in and read records; everything else (the blog, the home page's
     * parts) in the public site.
     *
     * @return list<string>
     */
    public static function frontends(string $path): array
    {
        if (str_starts_with($path, 'app/dashboard/')) {
            return [self::TARGET_ADMIN];
        }
        if ($path === 'resources/latest-posts.tsx') {
            return [self::TARGET_WEB];
        }
        if (str_starts_with($path, 'resources/') || $path === 'lib/auth-config.ts') {
            return [self::TARGET_WEB, self::TARGET_ADMIN];
        }

        return [self::TARGET_WEB];
    }

    public readonly string $contents;

    public function __construct(
        public readonly string $target,
        public readonly string $path,
        string $contents,
        public readonly string $mode = self::MODE_BLOCK,
        /** For MODE_ONCE: a glob (relative to the target root) that, when it matches, means the file already exists under another name. */
        public readonly ?string $existsGlob = null,
    ) {
        // The templates take their line endings from this package's own source files, which
        // git may have checked out with CRLF on Windows. Generated code is always LF.
        $this->contents = str_replace("\r\n", "\n", $contents);
    }
}
