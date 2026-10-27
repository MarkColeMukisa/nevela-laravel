<?php

namespace Nevela\Laravel\Notices;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Support\SchemaFacts;
use Throwable;

/**
 * The dashboard's bell: what happened behind the scenes, told to the people it concerns.
 *
 * Nevela tells people about forms sent, accounts made and closed, changes to their own
 * sign-in, jobs that failed and the trash being emptied. An app adds its own:
 *
 *     Notices::toPermission('orders.view', new Notice("Order {$order->number} was paid", url: "/dashboard/orders/{$order->id}"));
 *     Notices::send($order->customer, new Notice('Your order has shipped'));
 *
 * Telling someone never fails the work that caused it: a problem here is reported and
 * passed over. Before the table is migrated, nothing is told.
 */
final class Notices
{
    public const TABLE = 'nevela_notifications';

    /** The most people one notice is written for. */
    public const MOST_RECIPIENTS = 500;

    /** How long a notice is kept once read. One nobody reads is kept twice as long. */
    public const KEEP_DAYS = 90;

    public static function ready(): bool
    {
        return SchemaFacts::hasTable(self::TABLE);
    }

    /**
     * Tell these people: a user, a list of users or ids, or a query for them.
     * Returns how many were told.
     */
    public static function send(mixed $users, Notice $notice): int
    {
        if (! self::ready()) {
            return 0;
        }
        try {
            $ids = self::ids($users);
            foreach ($ids as $id) {
                self::write($id, $notice);
            }

            return count($ids);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    /** Tell everyone who may do something ("products.view"), apart from whoever did it. */
    public static function toPermission(string $permission, Notice $notice, mixed $except = null): int
    {
        if (! self::ready()) {
            return 0;
        }
        try {
            $query = Access::holders($permission);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
        if (is_object($except) && method_exists($except, 'getAuthIdentifier')) {
            $query->whereKeyNot($except->getAuthIdentifier());
        }

        return self::send($query, $notice);
    }

    /** Tell the administrators: about the app itself, which only they can do anything about. */
    public static function toAdmins(Notice $notice): int
    {
        if (! self::ready()) {
            return 0;
        }
        try {
            $query = Access::ready() ? Access::administrators() : Access::holders('*');
        } catch (Throwable $e) {
            report($e);

            return 0;
        }

        return self::send($query, $notice);
    }

    /**
     * Someone's latest notices, newest first, and how many they haven't read.
     *
     * @return array{items: list<array<string, mixed>>, unread: int}
     */
    public static function for(mixed $user, int $limit = 30): array
    {
        if (! self::ready()) {
            return ['items' => [], 'unread' => 0];
        }
        $id = (string) $user->getAuthIdentifier();
        $rows = DB::table(self::TABLE)->where('user_id', $id)->orderByDesc('updated_at')->orderByDesc('id')->limit($limit)->get();

        return [
            'items' => $rows->map(fn (object $row) => [
                'id' => $row->id,
                'kind' => $row->kind,
                'title' => $row->title,
                'body' => $row->body,
                'url' => $row->url,
                'tone' => $row->tone,
                'count' => (int) $row->count,
                'read' => $row->read_at !== null,
                'at' => Carbon::parse($row->updated_at)->toIso8601String(),
            ])->values()->all(),
            'unread' => DB::table(self::TABLE)->where('user_id', $id)->whereNull('read_at')->count(),
        ];
    }

    /** Mark one of someone's notices read, or all of them. Returns how many changed. */
    public static function markRead(mixed $user, ?string $id = null): int
    {
        if (! self::ready()) {
            return 0;
        }

        return DB::table(self::TABLE)
            ->where('user_id', (string) $user->getAuthIdentifier())
            ->whereNull('read_at')
            ->when($id !== null, fn ($query) => $query->where('id', $id))
            ->update(['read_at' => now()]);
    }

    /** Remove what has been kept long enough. Returns how many were removed. */
    public static function prune(): int
    {
        if (! self::ready()) {
            return 0;
        }

        return DB::table(self::TABLE)
            ->where(fn ($query) => $query
                ->where('read_at', '<', now()->subDays(self::KEEP_DAYS))
                ->orWhere('updated_at', '<', now()->subDays(self::KEEP_DAYS * 2)))
            ->delete();
    }

    /** @return list<string> */
    private static function ids(mixed $users): array
    {
        if ($users instanceof Builder) {
            return $users->limit(self::MOST_RECIPIENTS)->pluck($users->getModel()->getKeyName())->map(fn ($id) => (string) $id)->all();
        }
        $ids = [];
        foreach (is_iterable($users) ? $users : [$users] as $user) {
            $id = is_object($user) && method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : $user;
            if (is_scalar($id) && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return array_slice(array_values(array_unique($ids)), 0, self::MOST_RECIPIENTS);
    }

    /** One person's row: a new one, or the unread one of its group with one more. */
    private static function write(string $userId, Notice $notice): void
    {
        $now = now();
        $added = max(1, $notice->count);
        $fields = [
            'body' => $notice->body === null ? null : Str::limit($notice->body, 497),
            'tone' => $notice->tone === 'warning' ? 'warning' : 'info',
            'updated_at' => $now,
        ];
        if ($notice->group !== null) {
            // Locked while it is counted: two arriving at once must not both write the same count.
            $grown = DB::transaction(function () use ($userId, $notice, $fields, $added) {
                $open = DB::table(self::TABLE)->where('user_id', $userId)->where('group_key', $notice->group)->whereNull('read_at')->lockForUpdate()->first();
                if ($open === null) {
                    return false;
                }
                $count = (int) $open->count + $added;
                DB::table(self::TABLE)->where('id', $open->id)->update([
                    ...$fields,
                    'count' => $count,
                    'title' => Str::limit($notice->many !== null ? ($notice->many)($count) : $notice->title, 252),
                    'url' => $notice->manyUrl ?? $notice->url,
                ]);

                return true;
            });
            if ($grown) {
                return;
            }
        }
        DB::table(self::TABLE)->insert([
            ...$fields,
            // Ordered by time, so two written in the same second still list newest first.
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'kind' => Str::limit($notice->kind, 64, ''),
            'group_key' => $notice->group === null ? null : Str::limit($notice->group, 191, ''),
            'count' => $added,
            'title' => Str::limit($added > 1 && $notice->many !== null ? ($notice->many)($added) : $notice->title, 252),
            'url' => $added > 1 ? ($notice->manyUrl ?? $notice->url) : $notice->url,
            'created_at' => $now,
        ]);
    }
}
