<?php

namespace Nevela\Laravel\Notices;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Access\Visibility;
use Nevela\Laravel\Activity\Activity;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Support\Descriptor;

/**
 * What Nevela itself tells people, worded in one place. The controllers call these after
 * the thing has happened; see Notices for who is told and how.
 */
final class Announce
{
    /** A visitor sent a resource's public form: everyone who may see that resource is told. */
    public static function formReceived(Descriptor $descriptor, Model $record): void
    {
        $label = mb_strtolower($descriptor->label);
        $plural = mb_strtolower($descriptor->pluralLabel);
        $name = self::titleOf($descriptor, $record);

        self::toViewers("{$descriptor->table}.view", $record::class, [$record->getKey()], fn () => new Notice(
            'New '.$label.($name !== '' ? ": {$name}" : ''),
            url: "/dashboard/{$descriptor->slug}/{$record->getKey()}",
            kind: 'form.received',
            group: "form.received:{$descriptor->name}",
            many: fn (int $count) => "{$count} new {$plural}",
            manyUrl: "/dashboard/{$descriptor->slug}",
        ));
    }

    /** Someone followed the link in a form's confirmation email. */
    public static function formConfirmed(Descriptor $descriptor, Model $record): void
    {
        $name = self::titleOf($descriptor, $record);
        $plural = mb_strtolower($descriptor->pluralLabel);

        self::toViewers("{$descriptor->table}.view", $record::class, [$record->getKey()], fn () => new Notice(
            Str::ucfirst(mb_strtolower($descriptor->label)).' confirmed'.($name !== '' ? ": {$name}" : ''),
            url: "/dashboard/{$descriptor->slug}/{$record->getKey()}",
            kind: 'form.confirmed',
            group: "form.confirmed:{$descriptor->name}",
            many: fn (int $count) => "{$count} {$plural} confirmed",
            manyUrl: "/dashboard/{$descriptor->slug}",
        ));
    }

    /** Someone made an account, by signing up or with a social sign-in. */
    public static function accountCreated(mixed $user): void
    {
        Notices::toPermission('users.view', new Notice(
            "New account: {$user->name}",
            body: (string) $user->email,
            url: '/dashboard/access/users/'.$user->getAuthIdentifier(),
            kind: 'account.created',
            group: 'account.created',
            many: fn (int $count) => "{$count} new accounts",
            manyUrl: '/dashboard/access/users',
        ), except: $user);
    }

    /** Someone closed their own account: it can be restored under Deleted accounts. */
    public static function accountClosed(mixed $user): void
    {
        Notices::toPermission('users.delete', new Notice(
            "{$user->name} closed their account",
            body: (string) $user->email.'. It can be restored from Deleted accounts.',
            url: '/dashboard/access/deleted',
            kind: 'account.closed',
            group: 'account.closed',
            many: fn (int $count) => "{$count} accounts were closed",
        ), except: $user);
    }

    /**
     * Something changed about how someone signs in, or what they may do. Told to them,
     * so a change they didn't make is seen even where the email that says so isn't.
     */
    public static function toSelf(mixed $user, string $title, ?string $body = null, string $tone = 'info', ?string $url = '/dashboard/account/security'): void
    {
        Notices::send($user, new Notice($title, body: $body, url: $url, tone: $tone, kind: 'account.security'));
    }

    /**
     * The night's clear-out of the trash removed records for good.
     *
     * @param  array<string, int>  $removed  Resource name => how many
     */
    public static function trashEmptied(array $removed, int $days): void
    {
        $total = array_sum($removed);
        if ($total === 0) {
            return;
        }
        $parts = [];
        foreach ($removed as $resource => $count) {
            $parts[] = Str::headline($resource).' ('.number_format($count).')';
        }

        Notices::toAdmins(new Notice(
            ($total === 1 ? '1 record was' : number_format($total).' records were').' removed from the trash for good',
            body: "Deleted more than {$days} days ago: ".implode(', ', $parts).'.',
            url: '/dashboard/trash',
            kind: 'trash.emptied',
        ));
    }

    /**
     * Someone created, deleted or restored records (see Activity): told to everyone else who
     * may see them. One line per person and kind while unread: "Ada deleted 20 products".
     *
     * Where the model has a visibleTo scope, each is told of the ones they may see, and
     * counted as many: a teacher hears of the enrolments in their own courses.
     *
     * @param  list<string>  $ids  The records, for working out who may see which
     */
    public static function recordsChanged(mixed $user, string $action, Descriptor $descriptor, int $count, ?string $id, string $title, array $ids = []): void
    {
        $who = trim((string) ($user->name ?? '')) ?: 'Someone';
        $label = mb_strtolower($descriptor->label);
        $plural = mb_strtolower($descriptor->pluralLabel);
        $verb = ['created' => 'created', 'deleted' => 'deleted', 'restored' => 'restored'][$action];
        $one = "{$who} {$verb} ".(preg_match('/^[aeiou]/i', $label) ? 'an' : 'a')." {$label}".($title !== '' ? ": {$title}" : '');

        // Told of one of several, they aren't told which one's title was first.
        $notice = fn (bool $mayDelete, int $seen) => new Notice(
            $seen === 1 && $count === 1 ? $one : ($seen === 1 ? "{$who} {$verb} ".(preg_match('/^[aeiou]/i', $label) ? 'an' : 'a')." {$label}" : "{$who} {$verb} ".number_format($seen)." {$plural}"),
            url: Activity::url($descriptor, $action, $id, $mayDelete),
            kind: "record.{$action}",
            group: "record.{$action}:{$descriptor->name}:".$user->getAuthIdentifier(),
            many: fn (int $n) => "{$who} {$verb} ".number_format($n)." {$plural}",
            manyUrl: Activity::url($descriptor, $action, null, $mayDelete),
            count: $seen,
        );
        $model = "App\\Models\\{$descriptor->name}";
        $ids = $ids !== [] ? $ids : ($id !== null ? [$id] : []);
        if ($action !== 'deleted') {
            self::toViewers("{$descriptor->table}.view", $model, $ids, fn (?int $seen) => $notice(true, $seen ?? $count), $user);

            return;
        }
        // A deletion leads to the trash, for those who may use it, and to the list for the rest.
        try {
            $key = (new (Account::model()))->getKeyName();
            $deleters = Access::holders("{$descriptor->table}.delete")->whereKeyNot($user->getAuthIdentifier());
            $told = (clone $deleters)->limit(Notices::MOST_RECIPIENTS)->pluck($key)->all();
            $viewers = Access::holders("{$descriptor->table}.view")->whereKeyNot($user->getAuthIdentifier())->whereNotIn($key, $told);
        } catch (\Throwable $e) {
            report($e);

            return;
        }
        self::tell($deleters, $model, $ids, fn (?int $seen) => $notice(true, $seen ?? $count));
        self::tell($viewers, $model, $ids, fn (?int $seen) => $notice(false, $seen ?? $count));
    }

    /**
     * Tell everyone who may do something about these records, apart from whoever did it.
     *
     * @param  class-string<Model>  $model
     * @param  list<mixed>  $ids
     * @param  Closure(int|null): Notice  $notice  What to tell someone who may see this many of them, or all of them with null
     */
    private static function toViewers(string $permission, string $model, array $ids, Closure $notice, mixed $except = null): void
    {
        try {
            $query = Access::holders($permission);
        } catch (\Throwable $e) {
            report($e);

            return;
        }
        if (is_object($except) && method_exists($except, 'getAuthIdentifier')) {
            $query->whereKeyNot($except->getAuthIdentifier());
        }
        self::tell($query, $model, $ids, $notice);
    }

    /**
     * Tell these people, each of what they may see of the records. A model with no
     * visibleTo scope shows everyone all of them, and they are told together, as before.
     *
     * @param  Builder<Model>  $people
     * @param  class-string<Model>  $model
     * @param  list<mixed>  $ids
     * @param  Closure(int|null): Notice  $notice
     */
    private static function tell(Builder $people, string $model, array $ids, Closure $notice): void
    {
        if ($ids === [] || ! class_exists($model) || ! Visibility::narrows($model)) {
            Notices::send($people, $notice(null));

            return;
        }
        try {
            foreach ((clone $people)->limit(Notices::MOST_RECIPIENTS)->get() as $person) {
                $seen = Visibility::count($person, $model, $ids);
                if ($seen > 0) {
                    Notices::send($person, $notice($seen));
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** What a list would call the record, short: its title, or nothing when it has only an id. */
    private static function titleOf(Descriptor $descriptor, Model $record): string
    {
        return Activity::labelOf($descriptor, $record);
    }
}
