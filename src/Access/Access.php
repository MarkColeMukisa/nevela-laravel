<?php

namespace Nevela\Laravel\Access;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Models\Role;
use WeakMap;

/**
 * Who holds which roles, and what those come to.
 *
 * Laravel's gate asks here for any ability shaped like a permission ("products.view"), so
 * `$user->can('products.view')`, `Gate::authorize('users.edit')` and `@can` all work, and a
 * generated policy is one line per action.
 */
final class Access
{
    /** @var WeakMap<object, list<string>>|null Grants per user object, so a request asks the database once. */
    private static ?WeakMap $grants = null;

    private static bool $ready = false;

    /**
     * Whether the roles tables exist. Before an upgraded app has run its migrations they
     * don't, and until then everyone may do everything, as they could before.
     */
    public static function ready(): bool
    {
        // Only "yes" is remembered: the tables can appear later in the same process.
        return self::$ready = self::$ready || Schema::hasTable('nevela_roles');
    }

    /**
     * The grants a user holds, across all their roles. Patterns are kept as they are:
     * give the result to Permissions::granted(), and don't compare strings.
     *
     * @return list<string>
     */
    public static function grantsFor(mixed $user): array
    {
        if (! is_object($user)) {
            return [];
        }
        if (! self::ready()) {
            return ['*'];
        }
        self::$grants ??= new WeakMap;
        if (isset(self::$grants[$user])) {
            return self::$grants[$user];
        }
        if (! self::active($user)) {
            return self::$grants[$user] = [];
        }

        $grants = [];
        foreach (self::rolesOf($user) as $role) {
            array_push($grants, ...$role->grants);
        }

        return self::$grants[$user] = array_values(array_unique($grants));
    }

    public static function allows(mixed $user, string $permission): bool
    {
        return Permissions::granted(self::grantsFor($user), $permission);
    }

    /** An administrator: someone whose roles together come to everything. */
    public static function isAdmin(mixed $user): bool
    {
        return Permissions::hasAll(self::grantsFor($user));
    }

    /** False when the account has been switched off. An app with no such column has no such thing. */
    public static function active(mixed $user): bool
    {
        $attributes = method_exists($user, 'getAttributes') ? $user->getAttributes() : [];

        // Only an explicit "no" switches an account off: a column with nothing in it doesn't.
        return ! isset($attributes['active']) || (bool) $attributes['active'];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Role> */
    public static function rolesOf(mixed $user)
    {
        return Role::query()
            ->whereIn('id', DB::table('nevela_role_user')->where('user_id', (string) $user->getAuthIdentifier())->select('role_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Replace a user's roles.
     *
     * @param  list<string>  $roleIds
     */
    public static function assign(mixed $user, array $roleIds): void
    {
        $id = (string) $user->getAuthIdentifier();
        DB::transaction(function () use ($id, $roleIds) {
            DB::table('nevela_role_user')->where('user_id', $id)->delete();
            $rows = array_map(fn (string $role) => ['role_id' => $role, 'user_id' => $id], array_values(array_unique($roleIds)));
            if ($rows !== []) {
                DB::table('nevela_role_user')->insert($rows);
            }
        });
        self::forget();
    }

    /** Give a user one role by name, on top of what they have. False when there is no such role. */
    public static function grant(mixed $user, string $roleName): bool
    {
        if (! self::ready()) {
            return false;
        }
        $role = Role::query()->whereRaw('lower(name) = ?', [mb_strtolower($roleName)])->first();
        if (! $role) {
            return false;
        }
        DB::table('nevela_role_user')->insertOrIgnore(['role_id' => $role->id, 'user_id' => (string) $user->getAuthIdentifier()]);
        self::forget();

        return true;
    }

    /**
     * How many administrators can still sign in, leaving out `$except`.
     * The last one can't be removed, switched off or given a lesser role.
     *
     * Counted here as holding one role that comes to everything (ADMIN, or one like it).
     * Someone whose lesser roles happen to add up to everything isn't relied on to be the
     * app's last way in.
     */
    public static function otherAdmins(mixed $except = null): int
    {
        $model = Account::model();
        $instance = new $model;
        $table = $instance->getTable();
        $key = $instance->getKeyName();
        $hasActive = Schema::hasColumn($table, 'active');

        $all =Role::query()->get()->filter(fn (Role $role) => Permissions::hasAll($role->grants))->pluck('id')->all();
        $ids = DB::table('nevela_role_user')->whereIn('role_id', $all)->distinct()->pluck('user_id')->all();
        if ($except !== null) {
            $ids = array_values(array_diff($ids, [(string) $except->getAuthIdentifier()]));
        }
        if ($ids === []) {
            return 0;
        }

        return $model::query()->whereIn($key, $ids)->when($hasActive, fn ($query) => $query->where('active', true))->count();
    }

    /** Forget what has been worked out, after roles or who holds them change. */
    public static function forget(): void
    {
        self::$grants = null;
    }

    /** For tests that build and drop the tables within one process. */
    public static function reset(): void
    {
        self::$grants = null;
        self::$ready = false;
    }
}
