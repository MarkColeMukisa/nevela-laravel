<?php

namespace Nevela\Laravel\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Action;
use Nevela\Laravel\Support\Descriptor;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * A resource's own actions: what its descriptor's "actions" section names.
 *
 *   POST {slug}/{id}/_actions/{action}   { input }         on one record
 *   POST {slug}/_actions/{action}        { input }         on the resource as a whole
 *   POST {slug}/_actions/{action}        { ids, input }    a record action, on a selection
 *
 * One controller for every resource, like the trash: every action is reached the same
 * way and differs only in the class that does it, App\Nevela\Actions\<Resource>\<Action>.
 * Here is everything around that class: who may, whether it applies, whether what was
 * asked for is valid, and what the person is told when it fails.
 */
final class ActionController
{
    /** The most records a selection can be: what the dashboard's table can select at once, with room. */
    private const MOST = 500;

    public function record(Request $request, string $slug, string $id, string $action): JsonResponse
    {
        [$descriptor, $definition] = $this->find($slug, $action, 'record');
        $this->authorize($request, $descriptor, $definition);
        $record = $this->one($descriptor, $id) ?? throw new NotFoundHttpException;
        if (! $this->applies($descriptor, $definition, $record)) {
            return response()->json(['error' => "{$definition->label} can't be done to this ".strtolower($descriptor->label).' as it is now.', 'code' => 'NOT_APPLICABLE'], 409);
        }
        $input = $this->input($request, $definition);

        return $this->run($definition, function () use ($descriptor, $definition, $record, $input, $request) {
            $message = $this->handler($descriptor, $definition)->handle($record, $input);

            return ['message' => is_string($message) && $message !== '' ? $message : "{$definition->label}: done.", 'record' => $this->present($descriptor, $record, $request)];
        });
    }

    public function collection(Request $request, string $slug, string $action): JsonResponse
    {
        [$descriptor, $definition] = $this->find($slug, $action, null);
        $this->authorize($request, $descriptor, $definition);
        $input = $this->input($request, $definition);

        if ($definition->on === 'collection') {
            return $this->run($definition, function () use ($descriptor, $definition, $input) {
                $message = $this->handler($descriptor, $definition)->handle($input);

                return ['message' => is_string($message) && $message !== '' ? $message : "{$definition->label}: done."];
            });
        }

        // A record action, on several records at once.
        if (! $definition->many) {
            throw new NotFoundHttpException;
        }
        $ids = $request->input('ids');
        if (! is_array($ids) || ! array_is_list($ids) || $ids === [] || count($ids) > self::MOST) {
            return response()->json(['error' => 'Send the records as "ids": a list of 1 to '.self::MOST.'.'], 422);
        }

        return $this->run($definition, function () use ($descriptor, $definition, $input, $ids) {
            $handler = $this->handler($descriptor, $definition);
            $done = 0;
            $skipped = 0;
            foreach (array_unique(array_map('strval', array_filter($ids, 'is_scalar'))) as $id) {
                $record = $this->one($descriptor, $id);
                // One it no longer applies to, or that has gone, is passed over: the rest still want doing.
                if ($record === null || ! $this->applies($descriptor, $definition, $record)) {
                    $skipped++;

                    continue;
                }
                $handler->handle($record, $input);
                $done++;
            }
            $what = strtolower($done === 1 ? $descriptor->label : $descriptor->pluralLabel);

            return [
                'message' => "{$definition->label}: {$done} {$what}".($skipped > 0 ? ", and {$skipped} left as ".($skipped === 1 ? 'it was' : 'they were').'.' : '.'),
                'done' => $done,
                'skipped' => $skipped,
            ];
        });
    }

    /**
     * The resource and the action an address names.
     *
     * @param  'record'|null  $on  Where it must be, or either
     * @return array{Descriptor, Action}
     */
    private function find(string $slug, string $action, ?string $on): array
    {
        foreach (Nevela::all() as $descriptor) {
            $definition = $descriptor->slug === $slug ? ($descriptor->actions[$action] ?? null) : null;
            if ($definition !== null && ($on === null || $definition->on === $on)) {
                return [$descriptor, $definition];
            }
        }
        throw new NotFoundHttpException;
    }

    /** Its own permission, by name. Being able to edit the records is not being able to do this to them. */
    private function authorize(Request $request, Descriptor $descriptor, Action $action): void
    {
        if (! $request->user()?->can("{$descriptor->table}.{$action->key()}")) {
            throw new AccessDeniedHttpException("You don't have access to this.");
        }
    }

    /** The record with this id, if there is one and the person may see it. */
    private function one(Descriptor $descriptor, string $id): ?Model
    {
        $model = "App\\Models\\{$descriptor->name}";
        if (! class_exists($model) || ($descriptor->idType === 'int' && ! preg_match('/^[0-9]{1,18}$/', $id))) {
            return null;
        }
        $record = $model::query()->find($id);

        return $record !== null && Gate::allows('view', $record) ? $record : null;
    }

    private function applies(Descriptor $descriptor, Action $action, Model $record): bool
    {
        $values = [];
        foreach (array_keys($action->when) as $field) {
            $values[$field] = $record->getAttribute($descriptor->fields[$field]->column());
        }

        return $action->applies($values);
    }

    /**
     * What the action asked for, checked. A problem is reported against its field, the way
     * a form's is, so the sheet can show it there.
     *
     * @return array<string, mixed>
     */
    private function input(Request $request, Action $action): array
    {
        $sent = $request->input('input', []);
        $sent = is_array($sent) ? $sent : [];
        $validator = Validator::make($sent, $action->rules());
        $validator->after(function ($validator) use ($sent, $action) {
            foreach (array_keys($sent) as $key) {
                if (! isset($action->fields[$key])) {
                    $validator->errors()->add((string) $key, 'Unknown field.');
                }
            }
        });
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function handler(Descriptor $descriptor, Action $action): object
    {
        $class = "App\\Nevela\\Actions\\{$descriptor->name}\\{$action->className()}";
        if (! class_exists($class) || ! method_exists($class, 'handle')) {
            throw new \LogicException("{$action->label} has no class to do it. Run: php artisan nevela:generate {$descriptor->name}");
        }

        return app($class);
    }

    /**
     * Do it, all of it or none: what the class changed in the database is undone when it
     * throws, and the person is told that it didn't work and that nothing was changed.
     *
     * @param  callable(): array<string, mixed>  $do
     */
    private function run(Action $action, callable $do): JsonResponse
    {
        try {
            return response()->json(DB::transaction($do));
        } catch (HttpExceptionInterface|ValidationException $e) {
            throw $e; // the class said why itself: abort(409, …), or a validation error
        } catch (Throwable $e) {
            report($e);

            return response()->json(array_filter([
                'error' => "{$action->label} didn't work, and nothing was changed.",
                // Said to whoever is building the app, not to whoever is using it.
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]), 500);
        }
    }

    /**
     * The record as the API shows it, after the action: what the page it was pressed on now needs.
     *
     * @return array<string, mixed>|null
     */
    private function present(Descriptor $descriptor, Model $record, Request $request): ?array
    {
        $resource = "App\\Http\\Resources\\Nevela\\{$descriptor->name}Resource";
        if (! $record->exists || ! class_exists($resource)) {
            return null;
        }
        $fresh = $record->fresh();

        return $fresh === null ? null : (new $resource($fresh))->resolve($request);
    }
}
