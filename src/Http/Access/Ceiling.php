<?php

namespace Nevela\Laravel\Http\Access;

use Illuminate\Http\JsonResponse;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Access\Permissions;
use Nevela\Laravel\Models\Role;

/**
 * The rule that managing accounts is never a way to take more than you were given: you
 * act only on accounts that may do no more than you. Shared by the controllers that
 * change, delete, restore and remove them.
 */
trait Ceiling
{
    /**
     * A refusal when the account may do something the caller may not, and null otherwise.
     *
     * What the account may do is read from its roles, not from whether it is switched on:
     * a switched-off administrator is still not for a lesser manager to give a new
     * password and switch back on.
     */
    private function aboveCaller(mixed $caller, mixed $user, string $verb): ?JsonResponse
    {
        if (self::within(Access::grantsFor($caller), $user)) {
            return null;
        }

        return $this->holdsEverything($user)
            ? $this->refuse('ADMIN_ONLY', "Only an administrator can {$verb} an administrator's account.", 403)
            : $this->refuse('ABOVE_YOUR_OWN', "You can't {$verb} this account: it may do things your own roles don't allow.", 403);
    }

    /**
     * Whether everything an account's roles allow is covered by `$held`.
     *
     * @param  list<string>  $held
     * @param  iterable<Role>|null  $roles  The account's roles, when they are already at hand
     */
    private static function within(array $held, mixed $user, ?iterable $roles = null): bool
    {
        $theirs = [];
        foreach ($roles ?? Access::rolesOf($user) as $role) {
            array_push($theirs, ...$role->grants);
        }

        return Permissions::beyond($held, $theirs) === [];
    }

    /** Whether this account holds a role that comes to everything. */
    private function holdsEverything(mixed $user): bool
    {
        return Access::rolesOf($user)->contains(fn (Role $role) => Permissions::hasAll($role->grants));
    }
}
