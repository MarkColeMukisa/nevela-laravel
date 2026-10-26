<?php

namespace Nevela\Laravel\Notices;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
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

        Notices::toPermission("{$descriptor->table}.view", new Notice(
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

        Notices::toPermission("{$descriptor->table}.view", new Notice(
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

    /** What a list would call the record, short: its title, or nothing when it has only an id. */
    private static function titleOf(Descriptor $descriptor, Model $record): string
    {
        $field = $descriptor->fields[$descriptor->title()] ?? null;
        $value = $field !== null ? $record->getAttribute($field->column()) : null;

        return is_scalar($value) ? Str::limit(trim(strip_tags((string) $value)), 80) : '';
    }
}
