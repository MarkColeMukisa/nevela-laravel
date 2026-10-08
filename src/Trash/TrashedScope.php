<?php

namespace Nevela\Laravel\Trash;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Laravel's soft-delete scope, which hides deleted rows from every query, applied only
 * to a table that has the column to hide them by. See Concerns\Trashable.
 */
final class TrashedScope extends SoftDeletingScope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($model::trashes()) {
            parent::apply($builder, $model);
        }
    }
}
