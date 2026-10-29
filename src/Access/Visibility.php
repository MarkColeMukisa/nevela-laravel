<?php

namespace Nevela\Laravel\Access;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Which records each person sees, where a model says so with a `visibleTo` scope:
 *
 *     public function scopeVisibleTo(Builder $query, User $user): void
 *     {
 *         $query->whereHas('teacher', fn ($teacher) => $teacher->where('email', $user->email));
 *     }
 *
 * A permission says whether someone may see courses at all; this says which ones. Nevela
 * asks it for the list and everything counted from it (the cards, the charts, the export),
 * for one record (its page, editing it, deleting it, its actions and private files, the
 * trash), for the records a relation may point at, and for who the bell tells about one.
 *
 * Only for someone signed in. A seeder, the scheduler and a visitor to the public site
 * are not narrowed: the public site has publicQuery for that.
 */
final class Visibility
{
    /** Whether a model narrows what people see. */
    public static function narrows(Model|string $model): bool
    {
        $instance = is_string($model) ? new $model : $model;

        return $instance->hasNamedScope('visibleTo');
    }

    /** The query, narrowed to what this person may see. Nobody, or a model that doesn't narrow: as it was. */
    public static function narrow(Builder $query, mixed $user): Builder
    {
        if ($user instanceof Model && self::narrows($query->getModel())) {
            $query->visibleTo($user);
        }

        return $query;
    }

    /** Whether this person may see this record, whether or not it is in the trash. */
    public static function allows(mixed $user, Model $record): bool
    {
        if (! $user instanceof Model || ! $record->exists || ! self::narrows($record)) {
            return true;
        }

        return self::count($user, $record::class, [$record->getKey()]) === 1;
    }

    /**
     * How many of these records this person may see, those in the trash included: a
     * deletion is told of after the records have gone there.
     *
     * @param  class-string<Model>  $model
     * @param  list<mixed>  $ids
     */
    public static function count(mixed $user, string $model, array $ids): int
    {
        $query = $model::query()->whereKey($ids);
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        return self::narrow($query, $user)->count();
    }
}
