<?php

namespace Nevela\Laravel\Access;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Models\Role;

/**
 * Ten people for a new app, so its Users screen, its roles and its permissions have
 * something to show from the first run: two editors and eight users, one switched off.
 *
 * Like the starter admin they are made in the app's local database by the installer,
 * never by a migration, so they don't follow the app to a server. They all share one
 * password, which is printed when they are made.
 */
final class SampleUsers
{
    /** Name, role, and whether the account is switched on. The addresses are at example.com, which nobody owns. */
    public const PEOPLE = [
        ['Amara Okafor', Role::EDITOR, true],
        ['Daniel Kim', Role::EDITOR, true],
        ['Sofia Martinez', Role::USER, true],
        ['James Mwangi', Role::USER, true],
        ['Priya Nair', Role::USER, true],
        ['Lucas Silva', Role::USER, true],
        ['Hannah Schmidt', Role::USER, true],
        ['Omar Haddad', Role::USER, true],
        ['Grace Nakato', Role::USER, true],
        ['Noah Williams', Role::USER, false],
    ];

    /**
     * Create the first `count` of them who don't exist yet.
     *
     * @return list<array{name: string, email: string, role: string, active: bool}> Those created
     */
    public static function create(string $password, int $count = 10): array
    {
        $model = Account::model();
        $table = (new $model)->getTable();
        $hash = Hash::make($password);
        $created = [];

        foreach (array_slice(self::PEOPLE, 0, max(0, $count)) as [$name, $role, $active]) {
            $email = self::email($name);
            if (Account::findByEmail($email)) {
                continue;
            }
            $attributes = ['name' => $name, 'email' => $email, 'password' => $hash];
            if (Schema::hasColumn($table, 'email_verified_at')) {
                $attributes['email_verified_at'] = now();
            }
            if (Schema::hasColumn($table, 'active')) {
                $attributes['active'] = $active;
            }
            $user = (new $model)->forceFill($attributes);
            $user->save();
            Access::grant($user, $role);
            $created[] = ['name' => $name, 'email' => $email, 'role' => $role, 'active' => $active];
        }

        return $created;
    }

    /** "Amara Okafor" → amara.okafor@example.com */
    public static function email(string $name): string
    {
        return strtolower(str_replace(' ', '.', $name)).'@example.com';
    }
}
