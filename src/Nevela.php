<?php

namespace Nevela\Laravel;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Insights;
use Nevela\Laravel\Support\ListQuery;
use Nevela\Laravel\Support\Naming;

/**
 * Runtime helpers the generated controllers call. Kept small and readable: each method is
 * one step of Flare's REST contract.
 */
final class Nevela
{
    /** Written by `pnpm release`; the same number as the repository's git tag. */
    public const VERSION = '0.20.0';

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
        return self::listFor($query, self::resource($name), $request, $resourceClass);
    }

    /**
     * The same, for a descriptor that was handed over and not looked up: the public list
     * is the resource with only the fields anyone may see, so nothing else can be sorted,
     * filtered or searched by.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     */
    public static function listFor(Builder $query, Descriptor $resource, Request $request, string $resourceClass): JsonResponse
    {
        $list = ListQuery::parse($resource, $request->query(), (int) config('nevela.per_page', 25), (int) config('nevela.max_per_page', 100));
        if (! $list->ok()) {
            return response()->json(['error' => 'Invalid query.', 'issues' => $list->issues], 400);
        }

        self::narrow($query, $resource, $list);

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

    /** The search and the filters of a list request, applied. Its insights are narrowed the same way. */
    private static function narrow(Builder $query, Descriptor $resource, ListQuery $list): void
    {
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
            if (isset($filter['state'])) {
                // Published: its time has come. Scheduled: it has one, still to come. Draft: it has none.
                match ($filter['state']) {
                    'draft' => $query->whereNull($filter['column']),
                    'scheduled' => $query->where($filter['column'], '>', now()),
                    default => $query->whereNotNull($filter['column'])->where($filter['column'], '<=', now()),
                };

                continue;
            }
            if (isset($filter['confirmed'])) {
                $filter['confirmed'] ? $query->whereNotNull($filter['column']) : $query->whereNull($filter['column']);

                continue;
            }
            $field = $resource->fields[$filter['field']] ?? null;
            if ($filter['value'] !== null && $field?->kind === 'belongsTo' && ! self::couldBeId((string) $field->target, (string) $filter['value'])) {
                // Nothing belongs to a record that can't exist. Asked anyway, some databases
                // answer a number column given letters with an error instead of with no rows.
                $query->whereRaw('1 = 0');

                continue;
            }
            $filter['value'] === null
                ? $query->whereNull($filter['column'])
                : $query->where($filter['column'], $filter['value']);
        }
    }

    /**
     * A count is of the people who are really there: where a resource's form asks for an
     * address to be proved, the ones who haven't yet are left out. The list still shows
     * them, marked.
     */
    private static function proved(Builder $query, Descriptor $resource): void
    {
        if ($resource->public?->confirm !== null && method_exists($query->getModel(), 'scopeConfirmed')) {
            $query->confirmed();
        }
    }

    /** Whether a value has the shape of one of a resource's ids: digits, or a UUID. */
    private static function couldBeId(string $name, string $value): bool
    {
        try {
            $integers = self::resource($name)->idType === 'int';
        } catch (InvalidArgumentException) {
            return true; // not a resource this app describes: its ids are its own business
        }

        return $integers ? preg_match('/^[0-9]{1,18}$/', $value) === 1 : Str::isUuid($value);
    }

    /**
     * GET /{slug}/_insights?unit=day|week|month&q&filter[field] → { total, unit, series, breakdown }.
     *
     * What the dashboard's insights panel draws: how many records were created in each
     * period, and how they split across each field that is a choice (an enum or a yes/no).
     *
     * It takes the list's own search and filters, so the charts describe the rows the
     * table is showing. A whole-table chart above a filtered table would be wrong in a
     * prominent place.
     */
    public static function insights(Builder $query, string $name, Request $request): JsonResponse
    {
        $resource = self::resource($name);
        $unit = $request->query('unit', 'day');
        if (! is_string($unit) || ! isset(Insights::PERIODS[$unit])) {
            return response()->json(['error' => 'Invalid query.', 'issues' => [['param' => 'unit', 'message' => 'Must be day, week or month.']]], 400);
        }
        // The same parameters as the list. Where in the list someone is makes no difference to a count.
        $params = array_diff_key($request->query(), array_flip(['unit', 'page', 'perPage', 'sort', 'columns']));
        $list = ListQuery::parse($resource, $params, (int) config('nevela.per_page', 25), (int) config('nevela.max_per_page', 100));
        if (! $list->ok()) {
            return response()->json(['error' => 'Invalid query.', 'issues' => $list->issues], 400);
        }
        self::narrow($query, $resource, $list);
        self::proved($query, $resource);
        $base = fn () => (clone $query)->reorder();

        // Counted per day by the database, one grouped query over the created_at index,
        // and added up into weeks or months here: see Support\Insights.
        $now = now();
        $table = $query->getModel()->getTable();
        $day = $query->getConnection()->getDriverName() === 'sqlsrv' ? "CAST({$table}.created_at AS date)" : "DATE({$table}.created_at)";
        $perDay = $base()->toBase()
            ->where("{$table}.created_at", '>=', Insights::since($unit, $now))
            ->selectRaw("{$day} as day, count(*) as aggregate")
            ->groupByRaw($day)
            ->get()
            ->map(fn ($row) => [(string) $row->day, (int) $row->aggregate]);

        $breakdown = [];
        foreach ($resource->fields as $field) {
            if (! in_array($field->kind, ['enum', 'boolean'], true)) {
                continue;
            }
            if (count($breakdown) === 4) {
                break; // one grouped query each, and four charts is already a lot to read
            }
            $column = $field->column();
            $counts = [];
            foreach ($base()->toBase()->select($column.' as value')->selectRaw('count(*) as aggregate')->groupBy($column)->get() as $row) {
                $value = $row->value === null ? '' : ($field->kind === 'boolean' ? ($row->value ? 'true' : 'false') : (string) $row->value);
                $counts[$value] = ($counts[$value] ?? 0) + (int) $row->aggregate;
            }
            // Every choice the field has, in its own order, so one nobody has picked shows as zero.
            $values = $field->kind === 'boolean' ? ['true', 'false'] : $field->options;
            $slices = array_map(fn (string $value) => ['value' => $value, 'count' => $counts[$value] ?? 0], $values);
            if (($counts[''] ?? 0) > 0) {
                $slices[] = ['value' => '', 'count' => $counts['']];
            }
            $breakdown[] = ['field' => $field->name, 'kind' => $field->kind, 'slices' => $slices];
        }

        return response()->json([
            'total' => $base()->count(),
            'unit' => $unit,
            'series' => Insights::series($unit, $now, $perDay),
            'breakdown' => $breakdown,
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
        self::proved($query, $resource);
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

    /**
     * The same for a private file: what it is, and the address it is fetched from by
     * someone who may view the record, which names the record and never the file's key.
     *
     * @return array<string, mixed>|null
     */
    public static function privateFile(?string $key, string $slug, mixed $id, string $field): ?array
    {
        return Uploads::privateRef($key, $slug, $id, $field);
    }

    /**
     * A rich text field's HTML, as it is safe to show: only the tags and attributes in
     * config('nevela.rich_text'). Generated API resources send every rich text field through it.
     */
    public static function html(?string $html): ?string
    {
        return $html === null ? null : Support\Html::clean($html);
    }

    /** Whether a request is one Nevela answers (so errors use Flare's shape). */
    public static function handles(Request $request): bool
    {
        $prefix = trim((string) config('nevela.prefix', 'api'), '/');

        return $prefix === '' || $request->is($prefix, $prefix.'/*');
    }
}
