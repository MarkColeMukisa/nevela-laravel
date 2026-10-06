<?php

namespace Nevela\Laravel\Http\Access;

use Closure;
use Illuminate\Http\Request;
use Nevela\Laravel\Access\Access;
use Symfony\Component\HttpFoundation\Response;

/**
 * The users and roles endpoints need tables that an upgraded app doesn't have until it
 * has run its migrations. Until then they say so, in place of a database error.
 */
final class RolesAreSetUp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Access::ready()) {
            return response()->json(['error' => 'Roles need a migration that has not been run: php nevela migrate', 'code' => 'MIGRATION_NEEDED'], 409);
        }

        return $next($request);
    }
}
