<?php

namespace Nevela\Laravel\Access;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nevela\Laravel\Auth\Account;

/**
 * Accounts that were closed, and the emails that may not have a new one.
 *
 * A closed account is kept: its owner closed it, or someone deleted it from the Users
 * screen. It can't sign in, is signed out everywhere and is no longer among the users,
 * and it can be restored with everything it had. Removing it for good deletes the account
 * and leaves a fingerprint of its email behind, so that signing up again with the same
 * address is refused until someone allows it.
 *
 * The fingerprint is an HMAC of the address keyed with the app key: the address can't be
 * read back from it, and it is of no use to anyone holding only the database.
 */
final class ClosedAccounts
{
    public const BLOCKED = 'nevela_blocked_emails';

    private static bool $ready = false;

    /**
     * Whether the app has run the migration this needs. Until it has, deleting a user
     * removes them as it always did, and nothing here is offered.
     */
    public static function ready(): bool
    {
        if (self::$ready) {
            return true;
        }
        $model = Account::model();
        if (! class_exists($model)) {
            return false;
        }

        // Only "yes" is remembered: the column can arrive later in the same process.
        return self::$ready = Schema::hasTable(self::BLOCKED) && Schema::hasColumn((new $model)->getTable(), 'closed_at');
    }

    /** For tests that add and drop the tables within one process. */
    public static function reset(): void
    {
        self::$ready = false;
    }

    public static function isClosed(mixed $user): bool
    {
        $attributes = is_object($user) && method_exists($user, 'getAttributes') ? $user->getAttributes() : [];

        return isset($attributes['closed_at']);
    }

    /** The app's users, without the closed ones. */
    public static function open(): Builder
    {
        return Account::model()::query()->when(self::ready(), fn (Builder $query) => $query->whereNull('closed_at'));
    }

    /** Only the closed ones. Nobody, in an app that keeps none. */
    public static function closed(): Builder
    {
        return Account::model()::query()->when(self::ready(), fn (Builder $query) => $query->whereNotNull('closed_at'), fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    /** @return list<string> The ids of the closed accounts, as the roles table keeps ids: text. */
    public static function ids(): array
    {
        if (! self::ready()) {
            return [];
        }
        $model = Account::model();

        return self::closed()->pluck((new $model)->getKeyName())->map(fn ($id) => (string) $id)->all();
    }

    /**
     * An address as the person behind it, for telling whether two are the same mailbox:
     * lower case, without a "+tag", and for Gmail without the dots it ignores. So closing
     * mark@gmail.com also covers m.ark+new@gmail.com.
     */
    public static function canonical(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $plain = explode('+', $local, 2)[0];
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $plain = str_replace('.', '', $plain);
            $domain = 'gmail.com';
        }

        return ($plain !== '' ? $plain : $local).'@'.$domain;
    }

    public static function fingerprint(string $email): string
    {
        return hash_hmac('sha256', self::canonical($email), (string) config('app.key'));
    }

    /** "m•••@gmail.com": enough to recognise an address by, and not the address. */
    public static function hint(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');

        return $at === false || $at === 0 ? '•••' : mb_substr($email, 0, 1).'•••'.substr($email, $at);
    }

    /**
     * What stands in the way of a new account with this email: "closed" when a closed
     * account has it and could be restored, "blocked" when its account was removed for
     * good, and null when nothing does.
     *
     * @param  mixed  $except  An account whose own email this may be
     */
    public static function standing(string $email, mixed $except = null): ?string
    {
        if (! self::ready()) {
            return null;
        }
        $row = DB::table(self::BLOCKED)->where('fingerprint', self::fingerprint($email))->first();
        if (! $row || ($except !== null && $row->user_id !== null && $row->user_id === (string) $except->getAuthIdentifier())) {
            return null;
        }

        return $row->user_id !== null ? 'closed' : 'blocked';
    }

    /**
     * Close an account. It is signed out everywhere, and kept.
     *
     * @param  mixed  $by  Whoever is closing it: the account itself, or the person deleting it
     */
    public static function close(mixed $user, mixed $by = null): void
    {
        DB::transaction(function () use ($user, $by) {
            $user->forceFill(['closed_at' => now(), 'closed_by' => $by === null ? null : (string) $by->getAuthIdentifier()])->save();
            Account::revokeTokens($user);
            self::block((string) $user->email, (string) $user->getAuthIdentifier());
        });
        Access::forget();
    }

    /** Open a closed account again, as it was: its roles, its password, its second step. */
    public static function restore(mixed $user): void
    {
        DB::transaction(function () use ($user) {
            DB::table(self::BLOCKED)->where('user_id', (string) $user->getAuthIdentifier())->delete();
            $user->forceFill(['closed_at' => null, 'closed_by' => null])->save();
        });
        Access::forget();
    }

    /** Remove a closed account for good. Its email stays blocked until someone allows it. */
    public static function purge(mixed $user): void
    {
        $email = (string) $user->email;
        DB::transaction(function () use ($user, $email) {
            self::remove($user);
            self::block($email, null);
        });
    }

    /** Delete an account and what hangs off it. Nothing is kept. */
    public static function remove(mixed $user): void
    {
        $key = (string) $user->getAuthIdentifier();
        DB::transaction(function () use ($user, $key) {
            Account::revokeTokens($user);
            DB::table('nevela_role_user')->where('user_id', $key)->delete();
            foreach (['nevela_two_factor', 'nevela_passkeys'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('user_id', $key)->delete();
                }
            }
            $user->delete();
        });
        Access::forget();
    }

    /** Let an email whose account was removed for good be used again. False when there is no such entry. */
    public static function allow(string $id): bool
    {
        return DB::table(self::BLOCKED)->where('id', $id)->whereNull('user_id')->delete() > 0;
    }

    /** Remember an email by its fingerprint: for a closed account, or with no account left. */
    private static function block(string $email, ?string $userId): void
    {
        $fingerprint = self::fingerprint($email);
        $values = ['hint' => self::hint($email), 'user_id' => $userId, 'blocked_at' => now()];
        if (DB::table(self::BLOCKED)->where('fingerprint', $fingerprint)->exists()) {
            DB::table(self::BLOCKED)->where('fingerprint', $fingerprint)->update($values);
        } else {
            DB::table(self::BLOCKED)->insert(['id' => (string) Str::uuid(), 'fingerprint' => $fingerprint] + $values);
        }
    }
}
