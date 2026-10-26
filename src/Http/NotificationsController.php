<?php

namespace Nevela\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nevela\Laravel\Notices\Conditions;
use Nevela\Laravel\Notices\Notices;

/**
 * The dashboard's bell. Each person sees only their own notices; an administrator also
 * sees what is wrong with the app right now (Notices\Conditions).
 */
final class NotificationsController
{
    /** GET _nevela/notifications: `{ items, unread, conditions }`, the newest first. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([...Notices::for($user), 'conditions' => Conditions::for($user)])->header('Cache-Control', 'no-store');
    }

    /** POST _nevela/notifications/read: `{ id }` marks one read, no id marks them all. */
    public function read(Request $request): JsonResponse
    {
        $input = $request->validate(['id' => ['nullable', 'string', 'max:64']]);

        return response()->json(['read' => Notices::markRead($request->user(), $input['id'] ?? null)]);
    }
}
