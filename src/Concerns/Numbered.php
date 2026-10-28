<?php

namespace Nevela\Laravel\Concerns;

use Illuminate\Database\Eloquent\Model;
use Nevela\Laravel\Support\Numbering;

/**
 * Fields Laravel numbers itself, such as an order's number: ORD-0001, ORD-0002. A record
 * made without one is given the next, however it is made: the dashboard, the API, an
 * import, an action or your own code. One typed in is kept, and a save that empties it
 * leaves it as it was.
 *
 * The model says which in numbered(), written by nevela:generate from `"auto"` in the
 * descriptor.
 */
trait Numbered
{
    /** @return array<string, string> column => prefix */
    abstract protected function numbered(): array;

    public static function bootNumbered(): void
    {
        static::creating(function (Model $model): void {
            foreach ($model->numbered() as $column => $prefix) {
                if ((string) $model->getAttribute($column) === '') {
                    $model->setAttribute($column, Numbering::next($model->getTable(), $column, $prefix));
                }
            }
        });
        // A number is never taken away: emptied on a save, it stays what it was.
        static::updating(function (Model $model): void {
            foreach (array_keys($model->numbered()) as $column) {
                if ((string) $model->getAttribute($column) === '') {
                    $model->setAttribute($column, $model->getOriginal($column));
                }
            }
        });
    }
}
