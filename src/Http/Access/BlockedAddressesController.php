<?php

namespace Nevela\Laravel\Http\Access;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Nevela\Laravel\Auth\Confirmation;
use Nevela\Laravel\Security\Addresses;
use Symfony\Component\HttpFoundation\Response;

/**
 * The block list (Security\Addresses), for whoever holds its permissions.
 *
 *   GET    _nevela/blocked-addresses          the blocks in force, newest first
 *   POST   _nevela/blocked-addresses          { address, minutes?, note? }  block one
 *   DELETE _nevela/blocked-addresses/{id}     clear a block
 *
 * Blocking and clearing change who can reach the app, so they ask the person to confirm it's
 * them where the app asks for that (Auth\Confirmation). An administrator locked out by a block
 * clears it with `php nevela security:unblock <address>`, which needs no dashboard.
 */
final class BlockedAddressesController
{
    public function index(): JsonResponse
    {
        Gate::authorize('blocked_addresses.view');
        if (! Addresses::ready()) {
            return response()->json(['error' => 'The block list needs a migration that has not been run: php nevela migrate', 'code' => 'MIGRATION_NEEDED'], 409);
        }

        return response()->json(['data' => Addresses::current(), 'exempt' => array_values(array_filter((array) config('nevela.security.exempt', []), 'is_string'))]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('blocked_addresses.create');
        if (! Addresses::ready()) {
            return response()->json(['error' => 'The block list needs a migration that has not been run: php nevela migrate', 'code' => 'MIGRATION_NEEDED'], 409);
        }
        $input = $request->validate([
            'address' => ['required', 'string', 'max:64'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $key = Addresses::parse($input['address']);
        if ($key === null) {
            return self::issue('address', 'Write an address, such as 203.0.113.7, or an IPv6 address.');
        }
        if (Addresses::exempt($key)) {
            return self::issue('address', 'That address is never blocked: it is this server, or on the exempt list (nevela.security.exempt).');
        }
        if ($key === Addresses::of($request)) {
            return self::issue('address', "That's the address you are using now. Blocking it would lock you out.");
        }
        Confirmation::requireForAccess($request);
        $minutes = (int) ($input['minutes'] ?? 0);
        Addresses::block($key, 'manual', $minutes > 0 ? $minutes : null, (string) $request->user()->getAuthIdentifier(), $input['note'] ?? null);

        return response()->json(collect(Addresses::current())->firstWhere('address', $key), 201);
    }

    public function destroy(Request $request, string $id): Response
    {
        Gate::authorize('blocked_addresses.delete');
        if (! collect(Addresses::current())->contains('id', $id)) {
            return response()->json(['error' => 'No such block. It may have ended, or been cleared already.'], 404);
        }
        Confirmation::requireForAccess($request);
        Addresses::unblock($id);

        return response()->noContent();
    }

    private static function issue(string $path, string $message): JsonResponse
    {
        return response()->json(['error' => 'Validation failed.', 'issues' => [['path' => $path, 'message' => $message]]], 422);
    }
}
