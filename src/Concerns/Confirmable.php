<?php

namespace Nevela\Laravel\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Nevela\Laravel\Support\SchemaFacts;

/**
 * A model whose records hold an address that has to be proved: a list people join.
 *
 * A record made through the public form starts unconfirmed (`confirmed_at` is null) and
 * is confirmed when the link emailed to its address is followed. Until then it is left
 * out of counts and exports, and after two days it is removed. Whatever goes to
 * "everyone on the list" goes to the confirmed:
 *
 *     Subscriber::confirmed()->each(fn ($subscriber) => …);
 *
 * A record made any other way (in the dashboard, by a seeder, by an import) is confirmed
 * as it is made: someone signed in put it there, and there is nobody to ask.
 *
 * Generated models use it when their descriptor's public create has "confirm".
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait Confirmable
{
    /** Whether this model's table has the column. Only "yes" is remembered (by SchemaFacts): it can arrive at any time. */
    public static function confirms(): bool
    {
        $model = new static;

        return SchemaFacts::hasColumn($model->getTable(), 'confirmed_at', $model->getConnectionName());
    }

    /** Ask the database again next time. For a test that adds the column within one process. */
    public static function forgetConfirmation(): void
    {
        SchemaFacts::forget();
    }

    public static function bootConfirmable(): void
    {
        static::creating(function (self $model) {
            // The public form says "not yet" by setting the column to null itself. Anything
            // else that makes a record says nothing, and is taken at its word.
            if (! array_key_exists('confirmed_at', $model->getAttributes()) && static::confirms()) {
                $model->setAttribute('confirmed_at', $model->freshTimestamp());
            }
        });
    }

    public function initializeConfirmable(): void
    {
        $this->mergeCasts(['confirmed_at' => 'datetime']);
    }

    /** Whether this record's address has been proved. */
    public function isConfirmed(): bool
    {
        return $this->getAttribute('confirmed_at') !== null;
    }

    /** The records whose address has been proved: the ones to count, export and write to. */
    public function scopeConfirmed(Builder $query): void
    {
        if (static::confirms()) {
            $query->whereNotNull($this->qualifyColumn('confirmed_at'));
        }
    }

    /** The ones still waiting for their link to be followed. */
    public function scopeUnconfirmed(Builder $query): void
    {
        static::confirms() ? $query->whereNull($this->qualifyColumn('confirmed_at')) : $query->whereRaw('1 = 0');
    }
}
