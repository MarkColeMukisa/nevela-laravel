<?php

namespace Nevela\Laravel;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\ListQuery;
use Nevela\Laravel\Support\Naming;

/**
 * Runtime helpers the generated controllers call. Kept small and readable: each method is
 * one step of Flare's REST contract.
 */
final class Nevela
{
    /** Written by `pnpm release`; the same number as the repository's git tag. */
    public const VERSION = '0.5.1';

    /** @var array<string, Descriptor> */
    private static array $descriptors = [];

    /**
     * A short id for this installation, from where it is on disk. It gives nothing away,
     * and it differs between two apps on one machine, which is what it is for: telling
     * which app is answering on a port.
     */
    public static function fingerprint(): string
    {
        return substr(sha1(base_path()), 0, 12);
    }

    /** A resource's descriptor, from config('nevela.descriptors_path')/<kebab-name>.json. */
    public static function resource(string $name): Descriptor
    {
        if (isset(self::$descriptors[$name])) {
            return self::$descriptors[$name];
        }
        $path = rtrim(config('nevela.descriptors_path'), '/\\').DIRECTORY_SEPARATOR.Naming::kebab($name).'.json';
        if (! is_file($path)) {
            throw new InvalidArgumentException("No Nevela descriptor for {$name} at {$path}.");
        }

        return self::$descriptors[$name] = Descriptor::fromJson((string) file_get_contents($path));
    }

    /** @return list<Descriptor> */
    public static function all(): array
    {
        $dir = config('nevela.descriptors_path');
        $all = [];
        foreach (glob(rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $d = Descriptor::fromJson((string) file_get_contents($file));
            $all[] = self::$descriptors[$d->name] = $d;
        }

        return $all;
    }

    public static function forget(): void
    {
        self::$descriptors = [];
        Access\Permissions::forget();
    }

    /**
     * GET /{slug}: page, perPage, sort, q, filter[field] → { data, meta }.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     */
    public static function list(Builder $query, string $name, Request $request, string $resourceClass): JsonResponse
    {
        $resource = self::resource($name);
        $list = ListQuery::parse($resource, $request->query(), (int) config('nevela.per_page', 25), (int) config('nevela.max_per_page', 100));
        if (! $list->ok()) {
            return response()->json(['error' => 'Invalid query.', 'issues' => $list->issues], 400);
        }

        if ($list->q !== null) {
            $columns = array_map(fn ($f) => $f->column(), array_values(array_filter($resource->fields, fn ($f) => $f->searchable())));
            if ($columns !== []) {
                $pattern = '%'.addcslashes($list->q, '%_\\').'%';
                $query->where(function (Builder $where) use ($columns, $pattern) {
                    foreach ($columns as $column) {
                        $where->orWhereLike($column, $pattern);
                    }
                });
            }
        }

        foreach ($list->filters as $filter) {
            $filter['value'] === null
                ? $query->whereNull($filter['column'])
                : $query->where($filter['column'], $filter['value']);
        }

        $query->reorder()
            ->orderBy($list->sort['column'], $list->sort['direction'])
            ->orderBy($query->getModel()->getKeyName(), $list->sort['direction']);

        $page = $query->paginate($list->perPage, ['*'], 'page', $list->page);

        // One query for every file on the page, instead of one per record.
        foreach ($resource->fields as $field) {
            if ($field->kind === 'file') {
                Uploads::preload($page->getCollection()->pluck($field->column()));
            }
        }

        return response()->json([
            'data' => $resourceClass::collection($page->getCollection())->resolve($request),
            'meta' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'totalPages' => max(1, $page->lastPage()),
            ],
        ]);
    }

    /**
     * POST /{slug}/_bulk { items: [ … ] } → 201 { created, data }, or 422 { error, rows }.
     *
     * Several records in one go, for the dashboard's grid. Every row is checked before
     * anything is written, and they are saved in one transaction: a mistake on row 7
     * is reported against row 7 and nothing is created, where saving six and stopping
     * would leave someone working out which six.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @param  class-string<Http\ResourceRequest>  $requestClass
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     */
    public static function createMany(string $modelClass, string $requestClass, string $resourceClass, Request $request): JsonResponse
    {
        $most = max(1, (int) config('nevela.bulk_max', 500));
        $items = $request->input('items');
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            return response()->json(['error' => 'Send the rows to create as "items", a list with at least one row.'], 422);
        }
        if (count($items) > $most) {
            return response()->json(['error' => 'That is '.number_format(count($items))." rows. At most {$most} can be created at once; for more, import a file."], 422);
        }

        $checked = (new $requestClass)->many($items);
        if ($checked['problems'] !== []) {
            return self::rowsRefused($checked['problems'], count($items));
        }

        try {
            $created = DB::transaction(function () use ($modelClass, $checked) {
                $created = [];
                foreach ($checked['values'] as $index => $values) {
                    try {
                        $created[] = $modelClass::create($values);
                    } catch (UniqueConstraintViolationException $e) {
                        // Someone else created the same value between the check and the write.
                        throw new \RuntimeException((string) ($index + 1), 0, $e);
                    }
                }

                return $created;
            });
        } catch (\RuntimeException $e) {
            if (! $e->getPrevious() instanceof UniqueConstraintViolationException) {
                throw $e;
            }

            return self::rowsRefused([['row' => (int) $e->getMessage(), 'issues' => [['path' => '', 'message' => 'A record with one of these values was created a moment ago.']]]], count($items));
        }

        return response()->json([
            'created' => count($created),
            'data' => array_map(fn ($model) => (new $resourceClass($model->refresh()))->resolve($request), $created),
        ], 201);
    }

    /** @param list<array{row: int, issues: list<array{path: string, message: string}>}> $problems */
    private static function rowsRefused(array $problems, int $sent): JsonResponse
    {
        $wrong = count($problems);

        return response()->json([
            'error' => $wrong === 1
                ? "Nothing was created: row {$problems[0]['row']} needs fixing."
                : "Nothing was created: {$wrong} of the {$sent} rows need fixing.",
            'rows' => $problems,
        ], 422);
    }

    /**
     * GET /{slug}/_stats?field=status&days=7 → { total, current, previous, values }.
     * What Flare's ResourceStats card shows above a table.
     */
    public static function stats(Builder $query, string $name, Request $request): JsonResponse
    {
        $resource = self::resource($name);
        $days = filter_var($request->query('days', 7), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        if ($days === false) {
            return response()->json(['error' => 'Invalid query.', 'issues' => [['param' => 'days', 'message' => 'Must be between 1 and 365.']]], 400);
        }
        $key = $request->query('field');
        $field = is_string($key) ? ($resource->fields[$key] ?? null) : null;
        if ($key !== null && ($field === null || ! in_array($field->kind, ['enum', 'boolean'], true))) {
            return response()->json(['error' => 'Invalid query.', 'issues' => [['param' => 'field', 'message' => 'Must be an enum or boolean field.']]], 400);
        }

        $now = now();
        $since = $now->copy()->subDays($days);
        $before = $now->copy()->subDays($days * 2);
        $base = fn () => (clone $query)->reorder();

        $values = [];
        if ($field !== null) {
            $column = $field->column();
            foreach ($base()->toBase()->select($column.' as value')->selectRaw('count(*) as aggregate')->groupBy($column)->get() as $row) {
                $value = $field->kind === 'boolean' ? ($row->value ? 'true' : 'false') : (string) $row->value;
                $values[$value] = (int) $row->aggregate;
            }
        }

        return response()->json([
            'total' => $base()->count(),
            'current' => $base()->where('created_at', '>=', $since)->count(),
            'previous' => $base()->where('created_at', '>=', $before)->where('created_at', '<', $since)->count(),
            'values' => (object) $values,
        ]);
    }

    /**
     * What a client needs to show a stored file: its address, size and dimensions, and
     * each rendition's. Generated API resources send it beside the key, as `<field>File`.
     *
     * @return array<string, mixed>|null
     */
    public static function file(?string $key): ?array
    {
        return Uploads::ref($key);
    }

    /** Whether a request is one Nevela answers (so errors use Flare's shape). */
    public static function handles(Request $request): bool
    {
        $prefix = trim((string) config('nevela.prefix', 'api'), '/');

        return $prefix === '' || $request->is($prefix, $prefix.'/*');
    }
}
