<?php

namespace Nevela\Laravel\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Nevela\Laravel\Trash\TrashedScope;

/**
 * A model whose deleted records go to the trash, where they can be restored for a while.
 *
 * This is Laravel's own soft delete, with one difference: it only takes effect once the
 * table has its `deleted_at` column. An app that has upgraded and not yet migrated keeps
 * working exactly as before, with delete meaning delete, where plain SoftDeletes would
 * put `deleted_at is null` into every query on a table that has no such column.
 *
 * Every generated model uses it. Everything Laravel's soft delete offers is here:
 * `$model->restore()`, `$model->forceDelete()`, `$model->trashed()`, and on a query
 * `withTrashed()` and `onlyTrashed()`.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait Trashable
{
    use SoftDeletes;

    /** Whether this model's table has somewhere to keep a deleted record. Only "yes" is remembered. */
    private static bool $hasTrash = false;

    public static function trashes(): bool
    {
        if (static::$hasTrash) {
            return true;
        }
        $model = new static;

        // The column can arrive later in the same process (a migration run from a command),
        // so "no" is asked again and "yes" is kept.
        return static::$hasTrash = Schema::connection($model->getConnectionName())->hasColumn($model->getTable(), $model->getDeletedAtColumn());
    }

    /** Ask the database again next time. For a test that adds or drops the column within one process. */
    public static function forgetTrash(): void
    {
        static::$hasTrash = false;
    }

    /** In place of SoftDeletes' own: the same scope, applied only where there is a trash. */
    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new TrashedScope);
    }

    public static function bootTrashable(): void
    {
        // With no column to mark it in, a delete removes the row, as it always did.
        static::deleting(function (self $model) {
            if (! static::trashes()) {
                $model->forceDeleting = true;
            }
        });
    }
}
