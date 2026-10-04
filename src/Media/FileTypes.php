<?php

namespace Nevela\Laravel\Media;

/**
 * What each of Flare's file categories lets through. The same lists the dashboard's
 * upload widget checks in the browser; this is the check that counts.
 */
final class FileTypes
{
    public const CATEGORIES = [
        'any' => ['*/*'],
        'image' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'],
        'pdf' => ['application/pdf'],
        'video' => ['video/*'],
        'audio' => ['audio/*'],
        'text' => ['text/plain'],
        'csv' => ['text/csv'],
        'document' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text'],
        'spreadsheet' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.oasis.opendocument.spreadsheet'],
        'archive' => ['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'application/gzip', 'application/x-gzip', 'application/x-7z-compressed', 'application/vnd.rar', 'application/x-rar-compressed'],
    ];

    /** Types a browser would run or render as a page. Never stored, whatever a field accepts. */
    private const NEVER = ['text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/javascript', 'text/javascript', 'application/x-httpd-php', 'text/x-php', 'application/x-msdownload', 'application/x-dosexec'];

    /** Extensions a web server might run or a browser might render as a page. */
    private const NEVER_EXTENSIONS = ['html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'js', 'mjs', 'php', 'phtml', 'phar', 'exe', 'dll', 'bat', 'cmd', 'com', 'msi', 'sh', 'cgi', 'pl', 'py', 'jsp', 'asp', 'aspx', 'htaccess'];

    public static function forbidden(string $mime): bool
    {
        return in_array(strtolower(trim(explode(';', $mime)[0])), self::NEVER, true);
    }

    /** The extension a file is stored with: its own, unless that one could be run or rendered. */
    public static function safeExtension(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $extension !== '' && preg_match('/^[a-z0-9]{1,10}$/', $extension) && ! in_array($extension, self::NEVER_EXTENSIONS, true) ? $extension : 'bin';
    }

    /**
     * @param  list<string>  $accept  Categories, e.g. ["image", "pdf"]
     */
    public static function accepts(array $accept, string $mime): bool
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        if ($mime === '' || self::forbidden($mime)) {
            return false;
        }
        foreach ($accept as $category) {
            foreach (self::CATEGORIES[$category] ?? [] as $pattern) {
                if ($pattern === '*/*' || $pattern === $mime || (str_ends_with($pattern, '/*') && str_starts_with($mime, substr($pattern, 0, -1)))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * What a file is, from its contents. The type a browser sends is only a label, and a
     * renamed file carries the wrong one.
     */
    public static function sniff(string $path): string
    {
        $mime = function_exists('finfo_open') ? (string) @finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path) : '';

        return $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    /**
     * Whether what a file contains fits what it was sent as. Office documents and CSVs are
     * containers and plain text underneath, so those are compared by family, not exactly.
     */
    public static function consistent(string $declared, string $sniffed): bool
    {
        $declared = strtolower(trim(explode(';', $declared)[0]));
        if ($declared === $sniffed) {
            return true;
        }
        $family = fn (string $mime) => explode('/', $mime)[0];
        if (in_array($family($declared), ['image', 'video', 'audio'], true)) {
            return $family($declared) === $family($sniffed);
        }
        if ($declared === 'application/pdf') {
            return false;
        }

        // Zip-based documents, text formats and anything finfo has no opinion on.
        return in_array($sniffed, ['application/zip', 'application/octet-stream', 'text/plain', 'application/csv', 'text/csv', 'application/x-ole-storage', 'application/cdfv2'], true)
            || str_starts_with($sniffed, 'application/vnd.')
            || str_starts_with($sniffed, 'application/x-');
    }
}
