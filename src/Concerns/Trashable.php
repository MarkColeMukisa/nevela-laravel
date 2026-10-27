<?php

namespace Nevela\Laravel\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\SchemaFacts;
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

    /**
     * Whether this model's table has somewhere to keep a deleted record. The column can
     * arrive at any time (a migration), so "no" is asked again and "yes" is kept, by
     * SchemaFacts alone: a copy here would outlive SchemaFacts being told to forget, in a
     * process that runs for long (a queue worker).
     */
    public static function trashes(): bool
    {
        $model = new static;

        return SchemaFacts::hasColumn($model->getTable(), $model->getDeletedAtColumn(), $model->getConnectionName());
    }

    /** Ask the database again next time. For a test that adds or drops the column within one process. */
    public static function forgetTrash(): void
    {
        SchemaFacts::forget();
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

        // Its lines go with it: to the trash with it, or for good, with those already there.
        static::deleting(function (self $model) {
            $lines = Nevela::linesOf(class_basename($model));
            if ($lines === null) {
                return;
            }
            $query = $model->isForceDeleting() ? $lines['model']::withTrashed() : $lines['model']::query();
            foreach ($query->where($lines['column'], $model->getKey())->get() as $line) {
                $model->isForceDeleting() ? $line->forceDelete() : $line->delete();
            }
        });

        // And come back with it.
        static::restored(function (self $model) {
            $lines = Nevela::linesOf(class_basename($model));
            if ($lines === null) {
                return;
            }
            foreach ($lines['model']::onlyTrashed()->where($lines['column'], $model->getKey())->get() as $line) {
                $line->restore();
            }
        });

        // A private file goes when its record goes for good. In the trash the record still
        // exists, and so does its file, unreachable, for the day it is restored.
        static::deleted(function (self $model) {
            if ($model->exists) {
                return;
            }
            try {
                $resource = Nevela::resource(class_basename($model));
            } catch (InvalidArgumentException) {
                return; // a model that uses the trash without being a resource
            }
            foreach ($resource->fields as $field) {
                $key = $field->kind === 'file' && $field->private ? $model->getAttribute($field->column()) : null;
                // Not while another record of the table still points at the same file.
                if (is_string($key) && $key !== '' && ! $model->newQueryWithoutScopes()->where($field->column(), $key)->exists()) {
                    Uploads::discard($key);
                }
            }
        });
    }
}
