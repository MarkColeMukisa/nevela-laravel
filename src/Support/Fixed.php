<?php

namespace Nevela\Laravel\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Nevela\Laravel\Nevela;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Records that are not changed once they are made, such as a placed order: `"fixed": true`
 * in the descriptor, `--fixed` when the resource is made.
 *
 * Nobody signed in edits or deletes one, an administrator included, and its lines can't be
 * added to, changed or taken away. It moves on only through its actions (Accept, Cancel),
 * which say who did what in its history. Refusing is the model's business, from Eloquent's
 * own events, so the dashboard, the phone, an import and the app's own code meet the same
 * answer: 409, and why.
 *
 * Still allowed:
 * - finishing a record in the request that made it: its lines, and a total worked out from them;
 * - an action, which is how a fixed record is meant to change;
 * - the console, seeders, queued jobs and the scheduler: nobody is signed in there.
 *
 * Someone who may not edit the resource (a customer) can't choose, when they make one, the
 * values its actions set: a new order of theirs is pending whatever the request said.
 */
final class Fixed
{
    /** @var array<string, true> What this request has made, by class and key: still being finished. */
    private static array $made = [];

    private static int $allowing = 0;

    /** @var array<string, list<Descriptor>>|null The fixed resources each lines resource is lines of, by name */
    private static ?array $parents = null;

    /** Let go of what the last request made. */
    public static function forget(): void
    {
        self::$made = [];
        self::$parents = null;
    }

    /**
     * Do this with fixed records open to change: what an action does.
     *
     * @template T
     *
     * @param  callable(): T  $do
     * @return T
     */
    public static function allowing(callable $do): mixed
    {
        self::$allowing++;
        try {
            return $do();
        } finally {
            self::$allowing--;
        }
    }

    /** A model is being made: what its actions set is not for a customer to choose. */
    public static function creating(Model $model): void
    {
        $descriptor = self::descriptorOf($model);
        if ($descriptor !== null && $descriptor->fixed) {
            self::defaultsForCustomers($descriptor, $model);

            return;
        }
        self::guardLine($model);
    }

    /** A model was made: it can be finished in this request. */
    public static function created(Model $model): void
    {
        if (self::descriptorOf($model)?->fixed) {
            self::$made[self::key($model::class, $model->getKey())] = true;
        }
    }

    /** A model is being changed or deleted. */
    public static function changing(Model $model): void
    {
        $descriptor = self::descriptorOf($model);
        if ($descriptor !== null && $descriptor->fixed) {
            if (self::open() || isset(self::$made[self::key($model::class, $model->getKey())])) {
                return;
            }
            throw self::refusal($descriptor);
        }
        self::guardLine($model);
    }

    /** Nobody signed in, or inside an action. */
    private static function open(): bool
    {
        return self::$allowing > 0 || ! Auth::hasUser();
    }

    /** A line of a fixed record, such as an order's item: only while that record is being made. */
    private static function guardLine(Model $model): void
    {
        if (self::open()) {
            return;
        }
        $name = class_basename($model::class);
        if ($model::class !== "App\\Models\\{$name}" || ! Nevela::isLines($name)) {
            return;
        }
        if (self::$parents === null) {
            self::$parents = [];
            foreach (Nevela::all() as $descriptor) {
                if ($descriptor->fixed && $descriptor->items !== null) {
                    self::$parents[$descriptor->items][] = $descriptor;
                }
            }
        }
        foreach (self::$parents[$name] ?? [] as $parent) {
            if (($lines = Nevela::linesOf($parent->name)) === null) {
                continue;
            }
            $class = "App\\Models\\{$parent->name}";
            // The record it is a line of now, and the one it was: a line moved off a placed order changes it too.
            foreach (array_unique(array_filter([(string) $model->getAttribute($lines['column']), (string) $model->getOriginal($lines['column'])])) as $key) {
                if (! isset(self::$made[self::key($class, $key)])) {
                    throw self::refusal($parent);
                }
            }
        }
    }

    /** What its actions set takes the field's default when someone who may not edit the resource makes one. */
    private static function defaultsForCustomers(Descriptor $descriptor, Model $model): void
    {
        $user = Auth::user();
        if ($user === null || $user->can("{$descriptor->table}.edit")) {
            return;
        }
        foreach ($descriptor->actions as $action) {
            foreach (array_keys($action->set) as $name) {
                $field = $descriptor->fields[$name];
                $model->setAttribute($field->column(), $field->default);
            }
        }
    }

    private static function refusal(Descriptor $descriptor): ConflictHttpException
    {
        $what = strtolower($descriptor->label);

        return new ConflictHttpException("This {$what} can't be changed once it is made. What can still be done to it is on its page.");
    }

    private static function key(string $class, mixed $id): string
    {
        return $class.'#'.$id;
    }

    private static function descriptorOf(Model $model): ?Descriptor
    {
        $class = $model::class;
        if (! str_starts_with($class, 'App\\Models\\')) {
            return null;
        }
        $descriptor = Nevela::find(class_basename($class));

        return $descriptor !== null && "App\\Models\\{$descriptor->name}" === $class ? $descriptor : null;
    }
}
