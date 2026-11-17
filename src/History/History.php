<?php

namespace Nevela\Laravel\History;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nevela\Laravel\Notices\Notice;
use Nevela\Laravel\Notices\Notices;
use Nevela\Laravel\Support\Action;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\SchemaFacts;
use Throwable;

/**
 * A record's history: each action done to it, who did it, when, and what it changed.
 *
 *   GET _nevela/history/{slug}/{id}   oldest first, for whoever may see the record
 *
 * Where the activity log is the app's ("Mercy changed 12 orders"), this is the record's own
 * ("Accepted by Mercy Auma at 10:42"), and the customer an order belongs to reads it. Every
 * step is kept, never merged. And the person a record belongs to is told of each step
 * through the bell, unless they took it themselves.
 */
final class History
{
    public const TABLE = 'nevela_history';

    /** The most steps one record's history answers with. */
    public const MOST = 200;

    public static function ready(): bool
    {
        return SchemaFacts::hasTable(self::TABLE);
    }

    /**
     * An action was done to a record. Kept with the work it records: if that is undone, so is this.
     *
     * @param  array<string, mixed>  $before  The record's attributes before it, by column
     * @param  array<string, mixed>  $input  What the person filled in, checked
     */
    public static function record(Descriptor $descriptor, Action $action, Model $record, array $before, array $input, ?Model $user): void
    {
        if (! self::ready()) {
            return;
        }
        $fresh = $record->fresh() ?? $record;
        $changes = [];
        foreach ($descriptor->fields as $name => $field) {
            $column = $field->column();
            if (! array_key_exists($column, $before) || $field->private || in_array($field->kind, ['file', 'json', 'richtext'], true)) {
                continue;
            }
            $from = self::plain($before[$column]);
            $to = self::plain($fresh->getAttribute($column));
            if ($from !== $to) {
                $changes[$name] = ['from' => $from, 'to' => $to];
            }
        }
        DB::table(self::TABLE)->insert([
            'id' => (string) Str::uuid7(),
            'resource' => $descriptor->name,
            'record_id' => (string) $record->getKey(),
            'action' => $action->name,
            'label' => Str::limit($action->label, 250),
            'user_id' => $user?->getAuthIdentifier() === null ? null : (string) $user->getAuthIdentifier(),
            'changes' => $changes === [] ? null : json_encode($changes),
            'input' => $input === [] ? null : json_encode($input),
            'created_at' => now(),
        ]);
        DB::afterCommit(fn () => self::tellOwner($descriptor, $action, $fresh, $changes, $input, $user));
    }

    /**
     * A record's steps, oldest first, each with who took it.
     *
     * @return list<array{id: string, action: string, label: string, user: array{id: string, name: string|null}|null, changes: array<string, array{from: mixed, to: mixed}>, input: array<string, mixed>, at: string|null}>
     */
    public static function of(Descriptor $descriptor, string $id): array
    {
        if (! self::ready()) {
            return [];
        }
        $rows = DB::table(self::TABLE)->where('resource', $descriptor->name)->where('record_id', $id)
            ->orderBy('created_at')->orderBy('id')->limit(self::MOST)->get();
        $users = self::users($rows->pluck('user_id')->filter()->unique()->values()->all());

        return $rows->map(fn (object $row) => [
            'id' => (string) $row->id,
            'action' => (string) $row->action,
            'label' => (string) $row->label,
            'user' => $row->user_id === null ? null : ['id' => (string) $row->user_id, 'name' => $users[(string) $row->user_id] ?? null],
            'changes' => json_decode((string) $row->changes, true) ?: [],
            'input' => json_decode((string) $row->input, true) ?: [],
            'at' => $row->created_at === null ? null : \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601ZuluString(),
        ])->values()->all();
    }

    /**
     * The person it belongs to hears of it: "Your order JM-0014 is now Ready." Not when they
     * did it themselves, and never at the cost of the action, which is done by now.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     * @param  array<string, mixed>  $input
     */
    private static function tellOwner(Descriptor $descriptor, Action $action, Model $record, array $changes, array $input, ?Model $user): void
    {
        if ($descriptor->owner === null) {
            return;
        }
        try {
            $email = (string) $record->getAttribute($descriptor->fields[$descriptor->owner]->column());
            $model = config('auth.providers.users.model');
            if ($email === '' || ! is_string($model) || ! class_exists($model)) {
                return;
            }
            $owner = $model::query()->where('email', $email)->first();
            if ($owner === null || ($user !== null && (string) $owner->getAuthIdentifier() === (string) $user->getAuthIdentifier())) {
                return;
            }
            $what = strtolower($descriptor->label);
            $title = trim($what.' '.self::titleOf($descriptor, $record));
            // A status it was moved to says it best: "is now Ready". Else what was done: "Refund: your order".
            $status = null;
            foreach ($changes as $name => $change) {
                $field = $descriptor->fields[$name] ?? null;
                if ($field !== null && $field->kind === 'enum' && is_string($change['to'])) {
                    $status = $field->optionLabels[$change['to']] ?? Str::headline($change['to']);
                    break;
                }
            }
            $headline = $status !== null ? "Your {$title} is now {$status}" : "{$action->label}: your {$title}";
            $said = array_values(array_filter($input, fn ($value) => is_string($value) && trim($value) !== ''));
            Notices::send($owner, new Notice(
                $headline,
                body: $said === [] ? null : Str::limit((string) $said[0], 300),
                url: "/dashboard/{$descriptor->slug}/{$record->getKey()}",
                kind: 'history',
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function titleOf(Descriptor $descriptor, Model $record): string
    {
        $field = $descriptor->fields[$descriptor->title()] ?? null;
        $value = $field !== null ? $record->getAttribute($field->column()) : null;

        return is_scalar($value) ? Str::limit(trim(strip_tags((string) $value)), 60) : '';
    }

    /** A value as it is compared and kept: a date as text, a number as a number. */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof \BackedEnum => $value->value,
            is_bool($value), is_int($value), is_float($value), is_string($value), $value === null => $value,
            default => is_scalar($value) ? $value : null,
        };
    }

    /**
     * Names, by id.
     *
     * @param  list<string>  $ids
     * @return array<string, string|null>
     */
    private static function users(array $ids): array
    {
        $model = config('auth.providers.users.model');
        if ($ids === [] || ! is_string($model) || ! class_exists($model)) {
            return [];
        }
        $names = [];
        foreach ($model::query()->whereIn((new $model)->getKeyName(), $ids)->get() as $user) {
            $names[(string) $user->getAuthIdentifier()] = $user->getAttribute('name');
        }

        return $names;
    }
}
