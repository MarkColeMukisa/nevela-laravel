<?php

namespace Nevela\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Nevela\Laravel\Security\Addresses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns away an address on the block list (Security\Addresses), before anything else runs.
 * The web app's own server is never turned away: only the visitor it speaks for.
 */
final class BlockedAddresses
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = Addresses::of($request);
        if ($key !== null && ! Addresses::exempt($key) && ($block = Addresses::blocked($key)) !== null) {
            return response()->json([
                'error' => $block['until'] === null
                    ? 'Requests from your address are blocked. If that is a mistake, ask the people who run this app.'
                    : 'Requests from your address are blocked for now, after too many of the wrong kind. Try again later.',
                'code' => 'ADDRESS_BLOCKED',
                'until' => $block['until'],
            ], 403);
        }

        return $next($request);
    }
}
