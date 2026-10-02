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

    public function __construct(
        public readonly string $target,
        public readonly string $path,
        public readonly string $contents,
        public readonly string $mode = self::MODE_BLOCK,
        /** For MODE_ONCE: a glob (relative to the target root) that, when it matches, means the file already exists under another name. */
        public readonly ?string $existsGlob = null,
    ) {}
}
