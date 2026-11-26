<?php

namespace Nevela\Laravel\Activity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Notices\Announce;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\SchemaFacts;
use Throwable;

/**
 * The activity log: who created, changed, deleted or restored which of the app's records.
 *
 * Every write to a resource's model by someone signed in is noted as it happens, from
 * Eloquent's own events, so the dashboard, an import, an action and the app's own code are
 * all counted the same way. They are written once the request is done, one sealed entry per
 * person, change and resource, which is never changed after (Activity\Chain). The same thing
 * done to many records by one person within a couple of minutes shares a group, and the page
 * shows the group as one line with a count: deleting a selection, or importing a file, arrives
 * one record at a time.
 *
 * Seeding, the scheduler and visitors to the site aren't anyone signed in, and leave no
 * activity. A form a visitor sends tells people through the bell instead.
 */
final class Activity
{
    public const TABLE = 'nevela_activity';

    /** How long after the last of a kind the next one still joins its row. */
    public const MERGE_SECONDS = 120;

    /** How many ids a row keeps. */
    public const KEEP_IDS = 20;

    /** How long a row is kept. */
    public const KEEP_DAYS = 180;

    /** Created, deleted and restored are told to others through the bell; changes only appear here. */
    public const ANNOUNCED = ['created', 'deleted', 'restored'];

    /** @var array<string, array{action: string, descriptor: Descriptor, user: Model, id: string, label: string}> What this request has done, by record */
    private static array $pending = [];

    private static bool $flushing = false;

    public static function ready(): bool
    {
        return SchemaFacts::hasTable(self::TABLE);
    }

    /**
     * A model event, from the listeners NevelaServiceProvider registers. Only a resource's
     * model, written by someone signed in, is noted.
     */
    public static function noted(string $action, Model $model): void
    {
        if (self::$flushing || ! Auth::hasUser()) {
            return;
        }
        $descriptor = self::descriptorOf($model);
        if ($descriptor === null) {
            return;
        }
        if ($action === 'deleted' && method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
            $action = 'purged';
        }
        $user = Auth::user();
        $id = (string) $model->getKey();
        $label = self::labelOf($descriptor, $model);
        $key = $model::class.'#'.$id;

        // Only once the change is kept: a create inside a transaction that is rolled back
        // never happened. Outside a transaction this runs at once.
        DB::afterCommit(function () use ($key, $action, $descriptor, $user, $id, $label) {
            $before = self::$pending[$key]['action'] ?? null;
            // A change is the least of what can happen to a record: restoring one saves it,
            // and so does anything done to one just created.
            if ($action === 'updated' && $before !== null) {
                return;
            }
            self::$pending[$key] = ['action' => $action, 'descriptor' => $descriptor, 'user' => $user, 'id' => $id, 'label' => $label];
        });
    }

    /** Write what this request did. Runs once the response has gone (see NevelaServiceProvider). */
    public static function flush(): void
    {
        if (self::$pending === [] || self::$flushing) {
            return;
        }
        $pending = self::$pending;
        self::$pending = [];
        if (! self::ready()) {
            return;
        }
        self::$flushing = true;
        try {
            // One row per person, action and resource.
            $groups = [];
            foreach ($pending as $entry) {
                $who = (string) $entry['user']->getAuthIdentifier();
                $groups["{$who}|{$entry['action']}|{$entry['descriptor']->name}"][] = $entry;
            }
            // Each on its own: one that fails mustn't lose the rest.
            foreach ($groups as $entries) {
                try {
                    self::write($entries);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            self::$flushing = false;
        }
    }

    /** Forget what wasn't written: the start of another request in a long-running process. */
    public static function forget(): void
    {
        self::$pending = [];
    }

    /**
     * The activity someone may see, newest first: on the resources they may view.
     *
     * @param  array{resource?: string|null, action?: string|null, user?: string|null}  $filters
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, perPage: int, total: int, totalPages: int}}
     */
    public static function for(mixed $viewer, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $visible = array_values(array_filter(Nevela::all(), fn (Descriptor $d) => Access::allows($viewer, "{$d->table}.view")));
        $byName = $mayDelete = [];
        foreach ($visible as $descriptor) {
            $byName[$descriptor->name] = $descriptor;
            $mayDelete[$descriptor->name] = Access::allows($viewer, "{$descriptor->table}.delete");
        }
        if (! self::ready() || $byName === []) {
            return ['data' => [], 'meta' => ['page' => 1, 'perPage' => $perPage, 'total' => 0, 'totalPages' => 0]];
        }

        $query = DB::table(self::TABLE)
            ->whereIn('resource', array_keys($byName))
            ->when($filters['resource'] ?? null, fn ($q, $resource) => $q->where('resource', $resource))
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['user'] ?? null, fn ($q, $user) => $q->where('user_id', $user));
        if (Chain::ready()) {
            // One line per group: entries are stored one per request and joined here. Which lines
            // this page has, newest first by their latest entry: read from the newest entries back
            // (by seq, which is indexed) until enough groups are seen, then entries from before the
            // chain. Only those groups are added up, so the page costs the same however long the log.
            $need = $page * $perPage;
            $order = [];
            // The resources someone may see are left out as the entries are read, not in the query:
            // a list of them makes a database read the resource index and sort, where the seq index
            // alone reads the newest first. One chosen resource has an index with seq of its own.
            $scan = DB::table(self::TABLE)
                ->when($filters['resource'] ?? null, fn ($q, $resource) => $q->where('resource', $resource))
                ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
                ->when($filters['user'] ?? null, fn ($q, $user) => $q->where('user_id', $user));
            foreach (['chained', 'before'] as $source) {
                // A few entries a line is usual: read four times the lines wanted, and more only if that falls short.
                $chunk = min(1000, max(50, $need * 4));
                for ($offset = 0; count($order) < $need; $offset += $chunk) {
                    $batch = (clone $scan)
                        ->when($source === 'chained', fn ($q) => $q->whereNotNull('seq')->orderByDesc('seq'))
                        ->when($source === 'before', fn ($q) => $q->whereNull('seq')->orderByDesc('updated_at')->orderByDesc('id'))
                        ->offset($offset)->limit($chunk)->get(['group_id', 'resource']);
                    foreach ($batch as $entry) {
                        if (isset($byName[$entry->resource])) {
                            $order[(string) $entry->group_id] ??= count($order);
                        }
                    }
                    if ($batch->count() < $chunk) {
                        break;
                    }
                }
            }
            $groups = array_slice(array_keys($order), ($page - 1) * $perPage, $perPage);
            // Each line counted once, by the entry that began it (its id is the group's).
            $total = (clone $query)->whereColumn('group_id', 'id')->count();
            $rows = $groups === [] ? collect() : DB::table(self::TABLE)
                ->whereIn('group_id', $groups)
                ->select('group_id', 'user_id', 'action', 'resource', DB::raw('SUM(count) as count'), DB::raw('MIN(created_at) as created_at'), DB::raw('MAX(updated_at) as updated_at'))
                ->groupBy('group_id', 'user_id', 'action', 'resource')
                ->get()->sortBy(fn ($row) => $order[(string) $row->group_id])->values();
            // What a line of one record calls it, and which record: from its one entry.
            $single = $rows->filter(fn ($row) => (int) $row->count === 1)->pluck('group_id')->all();
            $firsts = $single === [] ? collect() : DB::table(self::TABLE)->whereIn('group_id', $single)->get(['group_id', 'label', 'record_ids'])->keyBy('group_id');
            $rows = $rows->map(function ($row) use ($firsts) {
                $row->id = $row->group_id;
                $row->label = $firsts[$row->group_id]->label ?? null;
                $row->record_ids = $firsts[$row->group_id]->record_ids ?? null;

                return $row;
            });
        } else {
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('updated_at')->orderByDesc('id')->forPage($page, $perPage)->get();
        }

        $model = Account::model();
        $key = (new $model)->getKeyName();
        $names = $model::query()->whereIn($key, $rows->pluck('user_id')->filter()->unique()->values()->all())->pluck('name', $key)->all();

        return [
            'data' => $rows->map(function (object $row) use ($byName, $names, $mayDelete) {
                $descriptor = $byName[$row->resource];
                $ids = json_decode((string) $row->record_ids, true) ?: [];
                $count = (int) $row->count;

                return [
                    'id' => $row->id,
                    'action' => $row->action,
                    'resource' => $descriptor->name,
                    'label' => $descriptor->label,
                    'pluralLabel' => $descriptor->pluralLabel,
                    'slug' => $descriptor->slug,
                    'count' => $count,
                    'title' => $count === 1 ? $row->label : null,
                    'recordId' => $count === 1 ? ($ids[0] ?? null) : null,
                    'url' => self::url($descriptor, $row->action, $count === 1 ? ($ids[0] ?? null) : null, $mayDelete[$descriptor->name]),
                    'user' => $row->user_id === null ? null : ['id' => $row->user_id, 'name' => $names[$row->user_id] ?? null],
                    'at' => Carbon::parse($row->updated_at)->toIso8601String(),
                    'startedAt' => Carbon::parse($row->created_at)->toIso8601String(),
                ];
            })->values()->all(),
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => (int) ceil($total / $perPage)],
        ];
    }

    /** Remove what has been kept long enough. Returns how many rows were removed. */
    public static function prune(): int
    {
        if (! self::ready()) {
            return 0;
        }
        $cutoff = now()->subDays(self::KEEP_DAYS);
        // Sealed entries go as a run from the start of the chain, which records where it now starts.
        $sealed = Chain::prune($cutoff->format('Y-m-d H:i:s'));
        // Entries from before the chain, by date as before.
        $query = DB::table(self::TABLE)->where('updated_at', '<', $cutoff);
        if (SchemaFacts::hasColumn(self::TABLE, 'seq')) {
            $query->whereNull('seq');
        }

        return $sealed + $query->delete();
    }

    /**
     * Where it can be seen: the record, the trash it went to, or the list. The trash is for
     * those who may delete that resource; anyone else is sent to the list.
     */
    public static function url(Descriptor $descriptor, string $action, ?string $id, bool $mayDelete = true): ?string
    {
        return match (true) {
            $action === 'purged' => null,
            $action === 'deleted' && $mayDelete => "/dashboard/trash?in={$descriptor->slug}",
            $action === 'deleted' => "/dashboard/{$descriptor->slug}",
            $id !== null => "/dashboard/{$descriptor->slug}/{$id}",
            default => "/dashboard/{$descriptor->slug}",
        };
    }

    /** What a list would call the record, short: its title, or nothing when it has only an id. */
    public static function labelOf(Descriptor $descriptor, Model $record): string
    {
        $field = $descriptor->fields[$descriptor->title()] ?? null;
        $value = $field !== null ? $record->getAttribute($field->column()) : null;

        return is_scalar($value) ? Str::limit(trim(strip_tags((string) $value)), 80) : '';
    }

    /** The resource a model is, by its name: one file looked for, once, however many records are saved. */
    private static function descriptorOf(Model $model): ?Descriptor
    {
        $class = $model::class;
        if (! str_starts_with($class, 'App\\Models\\')) {
            return null;
        }
        $descriptor = Nevela::find(class_basename($class));

        return $descriptor !== null && "App\\Models\\{$descriptor->name}" === $class ? $descriptor : null;
    }

    /**
     * One person's one kind of change to one resource: added to their row for it when that
     * is recent, else a row of its own. Then the bell, for those it is announced to.
     *
     * @param  non-empty-list<array{action: string, descriptor: Descriptor, user: Model, id: string, label: string}>  $entries
     */
    private static function write(array $entries): void
    {
        ['action' => $action, 'descriptor' => $descriptor, 'user' => $user] = $entries[0];
        $who = (string) $user->getAuthIdentifier();
        $ids = array_map(fn (array $entry) => $entry['id'], $entries);
        $added = count($entries);
        $now = now();

        $row = [
            'id' => (string) Str::uuid7(),
            'user_id' => $who,
            'action' => $action,
            'resource' => $descriptor->name,
            'count' => $added,
            'record_ids' => json_encode(array_slice($ids, 0, self::KEEP_IDS)),
            'label' => $entries[0]['label'] !== '' ? Str::limit($entries[0]['label'], 252) : null,
        ];
        if (Chain::ready()) {
            // Written once and sealed, never added to: the page joins a run of them into one line
            // by its group, the same person, change and resource within a couple of minutes.
            $open = DB::table(self::TABLE)
                ->where('user_id', $who)->where('action', $action)->where('resource', $descriptor->name)
                ->whereNotNull('seq')->where('created_at', '>=', $now->copy()->subSeconds(self::MERGE_SECONDS)->format('Y-m-d H:i:s'))
                ->orderByDesc('seq')->value('group_id');
            Chain::append([...$row, 'group_id' => $open ?? $row['id']]);
        } else {
            // Before the chain's migration: one row each, as the page then shows them.
            DB::table(self::TABLE)->insert([...$row, 'created_at' => $now, 'updated_at' => $now]);
        }

        // Lines are told of through what they are lines of: "Ada created an order", not its three items as well.
        if (in_array($action, self::ANNOUNCED, true) && ! Nevela::isLines($descriptor->name)) {
            Announce::recordsChanged($user, $action, $descriptor, $added, $added === 1 ? $ids[0] : null, $entries[0]['label'], $ids);
        }
    }
}
