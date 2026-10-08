<?php

namespace Nevela\Laravel\Trash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Descriptor;

/**
 * What a delete leaves behind, and what becomes of it.
 *
 * A deleted record is kept, hidden from every list, count and export, for a number of
 * days (config: nevela.trash.days, 30 unless changed). In that time it can be restored
 * from the dashboard's Trash page. After it, the record is removed for good: by
 * `nevela:trash`, which the scheduler runs each night, and whenever the trash is opened.
 */
final class Trash
{
    /** How many days a deleted record can be brought back, or null when it is kept until someone removes it. */
    public static function days(): ?int
    {
        $days = config('nevela.trash.days', 30);

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    /**
     * The resources that have a trash, with the model each one's records are.
     *
     * A resource is left out when its model doesn't keep deleted records: the app hasn't
     * migrated yet, or its model was changed not to.
     *
     * @return list<array{descriptor: Descriptor, model: class-string<Model>}>
     */
    public static function resources(): array
    {
        $found = [];
        foreach (Nevela::all() as $descriptor) {
            $model = "App\\Models\\{$descriptor->name}";
            if (class_exists($model) && method_exists($model, 'trashes') && $model::trashes()) {
                $found[] = ['descriptor' => $descriptor, 'model' => $model];
            }
        }
        usort($found, fn ($a, $b) => strcmp($a['descriptor']->pluralLabel, $b['descriptor']->pluralLabel));

        return $found;
    }

    /** @return array{descriptor: Descriptor, model: class-string<Model>}|null */
    public static function resource(string $slug): ?array
    {
        foreach (self::resources() as $resource) {
            if ($resource['descriptor']->slug === $slug) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Whether the signed-in person may do something to a deleted record.
     *
     * Laravel's names for these are `restore` and `forceDelete`, and a generated policy
     * has both. A policy written before the trash existed has neither, so it is asked
     * what it would say to `delete`: whoever could delete a record can deal with it here.
     */
    public static function may(string $ability, Model $record): bool
    {
        $policy = Gate::getPolicyFor($record);

        return Gate::allows($policy !== null && method_exists($policy, $ability) ? $ability : 'delete', $record);
    }

    /**
     * Whether they may see a resource's trash at all: whoever may delete, restore or remove
     * for good. Asked of a record that is nobody's in particular. A policy that keeps
     * restoring for administrators still lets the people who delete see what they deleted.
     */
    public static function maySee(string $model): bool
    {
        $blank = new $model;

        return self::may('delete', $blank) || self::may('restore', $blank) || self::may('forceDelete', $blank);
    }

    /** When a record deleted at `$deletedAt` is removed for good, or null when it is kept until someone does. */
    public static function expires(?Carbon $deletedAt): ?Carbon
    {
        $days = self::days();

        return $days === null || $deletedAt === null ? null : $deletedAt->copy()->addDays($days);
    }

    /**
     * Remove a deleted record for good. False when the database won't let it go because
     * other records, themselves in the trash, still belong to it.
     */
    public static function purge(Model $record): bool
    {
        try {
            $record->forceDelete();

            return true;
        } catch (QueryException $e) {
            // 23000 (and Postgres's 23503): a foreign key still points at this row.
            if (str_starts_with((string) $e->getCode(), '23')) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Remove everything whose time in the trash has run out.
     *
     * @param  class-string<Model>|null  $only  One resource's model, or all of them
     * @return array<string, int> How many were removed, by resource name
     */
    public static function purgeExpired(?string $only = null): array
    {
        $days = self::days();
        if ($days === null) {
            return [];
        }
        $cutoff = now()->subDays($days);
        $removed = [];
        foreach (self::resources() as $resource) {
            $model = $resource['model'];
            if ($only !== null && $only !== $model) {
                continue;
            }
            $count = 0;
            // One at a time, so a record something still belongs to is left and the rest go.
            $model::onlyTrashed()->where((new $model)->getQualifiedDeletedAtColumn(), '<', $cutoff)->orderBy((new $model)->getKeyName())
                ->chunkById(200, function ($records) use (&$count) {
                    foreach ($records as $record) {
                        $count += self::purge($record) ? 1 : 0;
                    }
                });
            if ($count > 0) {
                $removed[$resource['descriptor']->name] = $count;
            }
        }

        return $removed;
    }
}
