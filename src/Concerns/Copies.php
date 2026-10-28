<?php

namespace Nevela\Laravel\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Fields copied from a record this one points at, such as an order item's price from its
 * product. The copy is made when the record is, and again when it is pointed at another,
 * and kept otherwise: an order from last month keeps last month's prices.
 *
 * The model says which in copied(), written by nevela:generate from `"from":
 * "productId.price"` in the descriptor. What a request sends for such a field is left out
 * before it gets here, so the copy is the only way it is set.
 */
trait Copies
{
    /** @return array<string, array{0: string, 1: class-string<Model>, 2: string}> column => [the relation's column, the model it points at, the column copied] */
    abstract protected function copied(): array;

    public static function bootCopies(): void
    {
        static::saving(function (Model $model): void {
            foreach ($model->copied() as $column => [$key, $related, $source]) {
                $id = $model->getAttribute($key);
                if ($id === null || ($model->exists && ! $model->isDirty($key))) {
                    continue;
                }
                // Copied even from a record in the trash: it was there when it was chosen.
                $value = $related::query()->withoutGlobalScopes()->whereKey($id)->value($source);
                if ($value !== null) {
                    $model->setAttribute($column, $value);
                }
            }
        });
    }
}
