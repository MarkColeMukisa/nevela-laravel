<?php

namespace Nevela\Laravel\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Records that belong to whoever made them, such as a customer's orders: each person sees
 * their own, and the people who run the shop see all of them.
 *
 * The model says which field holds the owner in owner(), written by nevela:generate from
 * `"owner": "email"` in the descriptor. The field is matched to the signed-in person's email.
 *
 * - A person who may edit the resource (`orders.edit`) is staff: they see every record and what
 *   they send for the owner is kept.
 * - Anyone else sees only the records whose owner is their email, and a record they make is
 *   made theirs, whatever the request said for the owner.
 *
 * It is a `visibleTo` scope, so everything Nevela does with one applies: the list and its
 * counts, one record's page, editing and deleting, the trash, the bell. A model that writes
 * its own scopeVisibleTo keeps it.
 */
trait Owned
{
    /** The column that holds who a record belongs to. */
    abstract protected function owner(): string;

    public static function bootOwned(): void
    {
        static::creating(function (Model $model): void {
            $user = request()->user();
            // A seeder, the console, a visitor to the public site, staff: whatever was set is kept.
            if (! $user instanceof Model || $user->can($model->getTable().'.edit')) {
                return;
            }
            $model->setAttribute($model->owner(), $user->getAttribute('email'));
        });
    }

    public function scopeVisibleTo(Builder $query, Model $user): void
    {
        if (! $user->can($this->getTable().'.edit')) {
            $query->where($this->qualifyColumn($this->owner()), $user->getAttribute('email'));
        }
    }
}
