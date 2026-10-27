<?php

namespace Nevela\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Nevela\Laravel\Activity\Activity;

/**
 * The activity log, for those whose roles include `activity.view`. Each sees the activity
 * on the resources they may view, and nothing of the rest.
 */
final class ActivityController
{
    /** GET _nevela/activity: `{ data, meta }`, newest first. Takes resource, action, user, page and perPage. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('activity.view');
        $input = $request->validate([
            'resource' => ['nullable', 'string', 'max:64'],
            'action' => ['nullable', 'in:created,updated,deleted,restored,purged'],
            'user' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        // Not "nothing yet": nothing can be kept until the table is there.
        if (! Activity::ready()) {
            return response()->json(['error' => 'The activity log needs a migration that has not been run: php nevela migrate', 'code' => 'MIGRATION_NEEDED'], 409);
        }

        return response()->json(Activity::for(
            $request->user(),
            ['resource' => $input['resource'] ?? null, 'action' => $input['action'] ?? null, 'user' => $input['user'] ?? null],
            (int) ($input['page'] ?? 1),
            (int) ($input['perPage'] ?? 25),
        ))->header('Cache-Control', 'no-store');
    }
}
