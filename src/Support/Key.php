<?php

namespace Nevela\Laravel\Support;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Whether a string from an address could be a record's id at all.
 *
 * SQLite and MySQL answer "where id = 'nobody'" with no rows. Postgres checks the type
 * first: an id column is a number or a UUID, 'nobody' is neither, and the query is an
 * error, which reaches the visitor as a 500 where a 404 was meant. Inside a transaction
 * it also aborts everything else in it. So an id is looked at before the database is
 * asked, and one of the wrong shape is nobody's.
 */
final class Key
{
    /** A whole number that fits a bigint column. */
    public static function isInt(mixed $id): bool
    {
        return is_scalar($id) && preg_match('/^[0-9]{1,18}$/', (string) $id) === 1;
    }

    public static function isUuid(mixed $id): bool
    {
        return is_string($id) && Str::isUuid($id);
    }

    /**
     * Whether `$id` has the shape of this model's key: digits for a numbered key, and
     * whatever a model with Laravel's UUID or ULID keys says is one of its own. A model
     * with some other key of its own making is taken at its word.
     *
     * @param  Model|class-string<Model>  $model
     */
    public static function fits(Model|string $model, mixed $id): bool
    {
        $model = is_string($model) ? new $model : $model;
        if (! is_scalar($id)) {
            return false;
        }
        if (in_array($model->getKeyType(), ['int', 'integer'], true)) {
            return self::isInt($id);
        }

        $traits = class_uses_recursive($model);

        return match (true) {
            isset($traits[HasUuids::class]) => Str::isUuid((string) $id),
            isset($traits[HasUlids::class]) => Str::isUlid((string) $id),
            default => true,
        };
    }
}
