<?php

namespace Nevela\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Trash\Trash;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The trash: deleted records, by resource, to restore or to remove for good.
 *
 * One controller for every resource, where everything else about a resource is generated.
 * The trash asks the same three questions of all of them (what was deleted, bring it back,
 * get rid of it), and a generated copy per resource would be the same file many times.
 *
 * Who may use it is the resource's own policy: `restore` and `forceDelete` where it has
 * them, and what it says to `delete` where it was written before there was a trash.
 */
final class TrashController
{
    /**
     * GET _nevela/trash/_status: which resources have a trash this person may use, and how
     * long it keeps things. Nothing is counted and nothing is removed: this is asked on
     * the way to drawing a delete button, to say truthfully what pressing it will do.
     */
    public function status(): JsonResponse
    {
        $slugs = [];
        foreach (Trash::resources() as ['descriptor' => $descriptor, 'model' => $model]) {
            if (Trash::maySee($model)) {
                $slugs[] = $descriptor->slug;
            }
        }

        return response()->json(['days' => Trash::days(), 'resources' => $slugs]);
    }

    /**
     * GET _nevela/trash: how long things are kept, and how much of each resource is in it.
     * Only the resources this person may deal with. Anything past its time goes first.
     */
    public function index(): JsonResponse
    {
        Trash::purgeExpired();

        $resources = [];
        foreach (Trash::resources() as ['descriptor' => $descriptor, 'model' => $model]) {
            if (! Trash::maySee($model)) {
                continue;
            }
            $resources[] = [
                'name' => $descriptor->name,
                'label' => $descriptor->label,
                'pluralLabel' => $descriptor->pluralLabel,
                'slug' => $descriptor->slug,
                'icon' => $descriptor->icon,
                'count' => $model::onlyTrashed()->count(),
            ];
        }

        return response()->json(['days' => Trash::days(), 'resources' => $resources]);
    }

    /** GET _nevela/trash/{slug}?page=&perPage=: one resource's deleted records, the latest deletion first. */
    public function show(Request $request, string $slug): JsonResponse
    {
        ['descriptor' => $descriptor, 'model' => $model] = $this->resource($slug);
        $input = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:100']]);
        Trash::purgeExpired($model);

        $instance = new $model;
        $page = $model::onlyTrashed()
            ->orderByDesc($instance->getQualifiedDeletedAtColumn())->orderBy($instance->getQualifiedKeyName())
            ->paginate((int) ($input['perPage'] ?? 25), ['*'], 'page', (int) ($input['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn ($record) => [
                'id' => (string) $record->getKey(),
                'label' => self::label($descriptor, $record),
                'deletedAt' => $record->{$record->getDeletedAtColumn()}?->toJSON(),
                'expiresAt' => Trash::expires($record->{$record->getDeletedAtColumn()})?->toJSON(),
            ])->all(),
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'totalPages' => max(1, $page->lastPage())],
        ]);
    }

    /** POST _nevela/trash/{slug}/{id}/restore: back into the list, as it was. */
    public function restore(string $slug, string $id): JsonResponse
    {
        ['descriptor' => $descriptor, 'model' => $model] = $this->resource($slug);
        $record = $model::onlyTrashed()->find($id) ?? throw new NotFoundHttpException("No deleted {$this->noun($descriptor)} with that id.");
        if (! Trash::may('restore', $record)) {
            throw new AccessDeniedHttpException("You don't have access to this.");
        }

        // A record that must belong to another can't come back while that other is itself deleted.
        foreach ($descriptor->fields as $field) {
            if ($field->kind !== 'belongsTo' || ! $field->required) {
                continue;
            }
            $parent = "App\\Models\\{$field->target}";
            $key = $record->{$field->column()};
            if ($key !== null && class_exists($parent) && method_exists($parent, 'trashes') && $parent::trashes() && $parent::onlyTrashed()->whereKey($key)->exists()) {
                $what = strtolower($field->target);

                return response()->json(['error' => "Its {$what} is in the trash too. Restore the {$what} first.", 'code' => 'PARENT_IN_TRASH'], 409);
            }
        }
        $record->restore();

        return response()->json(['id' => (string) $record->getKey(), 'restored' => true]);
    }

    /** DELETE _nevela/trash/{slug}/{id}: gone for good. */
    public function destroy(string $slug, string $id): JsonResponse|Response
    {
        ['descriptor' => $descriptor, 'model' => $model] = $this->resource($slug);
        $record = $model::onlyTrashed()->find($id) ?? throw new NotFoundHttpException("No deleted {$this->noun($descriptor)} with that id.");
        if (! Trash::may('forceDelete', $record)) {
            throw new AccessDeniedHttpException("You don't have access to this.");
        }
        if (! Trash::purge($record)) {
            return response()->json(['error' => 'Other records in the trash still belong to this one. Delete those for good first.', 'code' => 'STILL_REFERENCED'], 409);
        }

        return response()->noContent();
    }

    /**
     * DELETE _nevela/trash/{slug}?confirm={slug}: everything of one resource, for good.
     * The slug has to be said again, so that this can't be the result of a mistyped address.
     */
    public function empty(Request $request, string $slug): JsonResponse
    {
        ['model' => $model] = $this->resource($slug);
        if ($request->query('confirm') !== $slug) {
            return response()->json(['error' => "To empty this trash, send confirm={$slug}.", 'code' => 'CONFIRM'], 422);
        }
        $removed = 0;
        $kept = 0;
        $instance = new $model;
        $model::onlyTrashed()->orderBy($instance->getQualifiedKeyName())->chunkById(200, function ($records) use (&$removed, &$kept) {
            foreach ($records as $record) {
                if (Trash::may('forceDelete', $record) && Trash::purge($record)) {
                    $removed++;
                } else {
                    $kept++;
                }
            }
        });

        return response()->json(['removed' => $removed, 'kept' => $kept]);
    }

    /** @return array{descriptor: Descriptor, model: class-string<\Illuminate\Database\Eloquent\Model>} */
    private function resource(string $slug): array
    {
        $resource = Trash::resource($slug) ?? throw new NotFoundHttpException('This resource has no trash.');
        if (! Trash::maySee($resource['model'])) {
            throw new AccessDeniedHttpException("You don't have access to this.");
        }

        return $resource;
    }

    private function noun(Descriptor $descriptor): string
    {
        return strtolower($descriptor->label);
    }

    /** What to call a deleted record: its title, the way a list would, and its id when it has none. */
    private static function label(Descriptor $descriptor, mixed $record): string
    {
        // The field the descriptor names, or the first piece of text it has, as the dashboard does.
        $field = $descriptor->titleField !== null ? ($descriptor->fields[$descriptor->titleField] ?? null) : null;
        foreach ($field === null ? $descriptor->fields : [] as $candidate) {
            if ($candidate->kind === 'string') {
                $field = $candidate;
                break;
            }
        }
        $value = $field ? $record->getAttribute($field->column()) : null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : (string) $record->getKey();
    }
}
