<?php

namespace Nevela\Laravel\Generator;

use RuntimeException;

/**
 * Writes generated files without trampling your code.
 *
 * Block files hold one region between `nevela:generated:start hash=…` and
 * `nevela:generated:end`. The hash records what was generated; if the region no longer
 * matches it, you edited it, and the file is skipped (reported as "edited") unless forced.
 * Code outside the region is never touched.
 */
final class Writer
{
    public const START = 'nevela:generated:start';

    public const END = 'nevela:generated:end';

    /** @param array<string, string> $roots target => absolute directory */
    public function __construct(private readonly array $roots, private readonly bool $force = false) {}

    /**
     * @param  iterable<GeneratedFile>  $files
     * @return list<array{status: string, path: string}> status: created|updated|unchanged|edited|exists|skipped
     */
    public function write(iterable $files): array
    {
        $report = [];
        foreach ($files as $file) {
            $root = $this->roots[$file->target] ?? null;
            if ($root === null) {
                $report[] = ['status' => 'skipped', 'path' => "{$file->target}:{$file->path}"];

                continue;
            }
            $absolute = rtrim($root, '/\\').DIRECTORY_SEPARATOR.$file->path;
            $report[] = ['status' => $this->writeOne($file, $root, $absolute), 'path' => $absolute];
        }

        return $report;
    }

    private function writeOne(GeneratedFile $file, string $root, string $absolute): string
    {
        if ($file->mode === GeneratedFile::MODE_ONCE) {
            $globbed = $file->existsGlob ? glob(rtrim($root, '/\\').DIRECTORY_SEPARATOR.$file->existsGlob) : [];
            if (is_file($absolute) || $globbed) {
                return 'exists';
            }

            return $this->put($absolute, $file->contents) ?? 'created';
        }

        if (! is_file($absolute)) {
            return $this->put($absolute, self::stamp($file->contents)) ?? 'created';
        }

        $current = (string) file_get_contents($absolute);
        $existing = self::block($current);
        $fresh = self::block(self::stamp($file->contents));
        if ($fresh === null) {
            throw new RuntimeException("Generated template for {$file->path} has no generated block.");
        }
        if ($existing === null) {
            return $this->force ? ($this->put($absolute, self::stamp($file->contents)) ?? 'updated') : 'edited';
        }
        if (! $this->force && $existing['hash'] !== self::hash($existing['body'])) {
            return 'edited';
        }
        if (str_replace("\r\n", "\n", $existing['body']) === $fresh['body']) {
            return 'unchanged';
        }
        $updated = substr($current, 0, $existing['offset']).$fresh['raw'].substr($current, $existing['offset'] + strlen($existing['raw']));

        return $this->put($absolute, $updated) ?? 'updated';
    }

    /** Fill the `hash=` of every start marker from the body that follows it. */
    public static function stamp(string $contents): string
    {
        $block = self::block($contents);
        if ($block === null) {
            return $contents;
        }
        $line = $block['startLine'];
        $stamped = preg_replace('/'.preg_quote(self::START, '/').'( hash=\w*)?/', self::START.' hash='.self::hash($block['body']), $line, 1);

        return substr($contents, 0, $block['offset']).$stamped.substr($contents, $block['offset'] + strlen($line));
    }

    /**
     * The generated region: from the start of the start-marker line through the end of
     * the end-marker line.
     *
     * @return array{offset: int, raw: string, startLine: string, body: string, hash: string}|null
     */
    public static function block(string $contents): ?array
    {
        $start = strpos($contents, self::START);
        if ($start === false) {
            return null;
        }
        $lineStart = strrpos(substr($contents, 0, $start), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $startLineEnd = strpos($contents, "\n", $start);
        $end = strpos($contents, self::END, $start);
        if ($startLineEnd === false || $end === false) {
            return null;
        }
        $endLineEnd = strpos($contents, "\n", $end);
        $endLineEnd = $endLineEnd === false ? strlen($contents) : $endLineEnd + 1;
        $endLineStart = strrpos(substr($contents, 0, $end), "\n") + 1;

        $startLine = substr($contents, $lineStart, $startLineEnd + 1 - $lineStart);
        preg_match('/hash=(\w*)/', $startLine, $m);

        return [
            'offset' => $lineStart,
            'raw' => substr($contents, $lineStart, $endLineEnd - $lineStart),
            'startLine' => $startLine,
            'body' => substr($contents, $startLineEnd + 1, $endLineStart - $startLineEnd - 1),
            'hash' => $m[1] ?? '',
        ];
    }

    public static function hash(string $body): string
    {
        return substr(hash('sha256', str_replace("\r\n", "\n", $body)), 0, 12);
    }

    private function put(string $absolute, string $contents): ?string
    {
        $dir = dirname($absolute);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }
        file_put_contents($absolute, $contents);

        return null;
    }
}
