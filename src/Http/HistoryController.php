<?php

namespace Nevela\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Nevela\Laravel\History\History;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Key;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One record's history: GET _nevela/history/{slug}/{id}. Whoever may see the record may
 * read it, the customer an order belongs to included: it is about them.
 */
final class HistoryController
{
    public function show(string $slug, string $id): JsonResponse
    {
        $descriptor = null;
        foreach (Nevela::all() as $candidate) {
            if ($candidate->slug === $slug) {
                $descriptor = $candidate;
                break;
            }
        }
        $model = $descriptor === null ? null : "App\\Models\\{$descriptor->name}";
        if ($model === null || ! class_exists($model) || ! Key::fits($model, $id)) {
            throw new NotFoundHttpException;
        }
        $record = $model::query()->find($id);
        // One they may not see isn't there for them, as on its own page.
        if ($record === null || ! Gate::allows('view', $record)) {
            throw new NotFoundHttpException;
        }
        if (! History::ready()) {
            return response()->json(['error' => 'Histories need a migration that has not been run: php nevela migrate', 'code' => 'MIGRATION_NEEDED'], 409);
        }

        return response()->json(['data' => History::of($descriptor, (string) $record->getKey())])->header('Cache-Control', 'no-store');
    }
}
