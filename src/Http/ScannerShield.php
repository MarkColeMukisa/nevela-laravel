<?php

namespace Nevela\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Nevela\Laravel\Security\Addresses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scanners turned away: the requests no Nevela app ever answers, sent by bots looking for a
 * site to break into. `/.env` and its cousins, WordPress's login and admin, phpMyAdmin, a
 * `.git` folder, PHPUnit's eval script left in vendor.
 *
 * Each answers 404 at once, before the app does any work, and counts against the address it
 * came from (`nevela-scan:<address>`): each is a strike, and an address that keeps probing is
 * blocked for a while (Security\Addresses).
 * Switched off with nevela.security.scanners = false; more paths in nevela.security.scanner_paths.
 */
final class ScannerShield
{
    /** Regular expressions on the path, without the leading slash. */
    public const PROBES = [
        '#(^|/)\.env(\.|$)#i',
        '#(^|/)[^/]*\.env$#i',
        '#(^|/)\.git(/|$)#i',
        '#(^|/)\.(aws|ssh|svn|hg|DS_Store|htpasswd)(/|$)#i',
        '#(^|/)wp-(admin|login|content|includes|config)#i',
        '#(^|/)xmlrpc\.php$#i',
        '#(^|/)(phpmyadmin|pma|myadmin|adminer)(\.php)?(/|$)#i',
        '#(^|/)vendor/phpunit/#i',
        '#(^|/)cgi-bin/#i',
        '#(^|/)(config|configuration|settings)\.(php|inc|bak|old)$#i',
        '#\.(sql|bak|swp)$#i',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (config('nevela.security.scanners', true) && self::isProbe($request->path())) {
            // Counted for the visitor, never for the web app's own server (Security\Addresses::of).
            $key = Addresses::of($request);
            if ($key !== null) {
                RateLimiter::hit('nevela-scan:'.$key, 3600);
                Addresses::strike($key, 'scanner');
            }

            return response()->json(['error' => 'Not found.'], 404);
        }

        return $next($request);
    }

    /** Whether a path is one only a scanner asks for. */
    public static function isProbe(string $path): bool
    {
        $path = ltrim(rawurldecode($path), '/');
        foreach ([...self::PROBES, ...array_filter((array) config('nevela.security.scanner_paths', []), 'is_string')] as $pattern) {
            if (@preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }
}
