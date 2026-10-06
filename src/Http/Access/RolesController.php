<?php

namespace Nevela\Laravel\Http\Access;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Access\Permissions;
use Nevela\Laravel\Http\Auth\Answers;
use Nevela\Laravel\Models\Role;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Roles: named sets of grants.
 *
 * The same ceiling as for users applies: a role can be given only what its editor holds,
 * so editing roles is never a way to gain a permission. The ADMIN role always grants
 * everything, and the three built-in roles keep their names and can't be deleted.
 */
final class RolesController
{
    use Answers;

    /** GET _nevela/permissions: every permission there is, shaped for the roles screen. */
    public function catalog(): JsonResponse
    {
        Gate::authorize('roles.view');

        return response()->json(['modules' => Permissions::catalog(), 'total' => count(Permissions::keys())]);
    }

    /** GET _nevela/roles. Open to anyone who manages users too: the user form lists them. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->can('roles.view') && ! $user->can('users.view')) {
            throw new AccessDeniedHttpException("You don't have access to this.");
        }
        $counts = DB::table('nevela_role_user')->groupBy('role_id')->selectRaw('role_id, count(*) as users')->pluck('users', 'role_id');
        $held = Access::grantsFor($user);
        $roles = Role::query()->orderByDesc('is_system')->orderBy('name')->get();

        return response()->json(['data' => $roles->map(fn (Role $role) => self::present($role, (int) ($counts[$role->id] ?? 0), $held))->all()]);
    }

    /** GET _nevela/roles/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        Gate::authorize('roles.view');
        $role = $this->find($id);

        return response()->json(self::present($role, $this->users($role), Access::grantsFor($request->user())));
    }

    /** POST _nevela/roles */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('roles.create');
        $input = $this->validated($request, null);
        if ($refused = $this->beyondCaller($request, $input['grants'])) {
            return $refused;
        }
        $role = Role::query()->create(['name' => $input['name'], 'description' => $input['description'] ?? null, 'grants' => $input['grants'], 'is_system' => false]);

        return response()->json(self::present($role, 0, Access::grantsFor($request->user())), 201);
    }

    /** PATCH _nevela/roles/{id}: any of name, description, grants. */
    public function update(Request $request, string $id): JsonResponse
    {
        Gate::authorize('roles.edit');
        $role = $this->find($id);
        $input = $this->validated($request, $role);
        $held = Access::grantsFor($request->user());

        // A role that already allows more than its editor holds is not theirs to reshape.
        if (Permissions::beyond($held, $role->grants) !== []) {
            return $this->refuse('BEYOND_YOUR_OWN', "You can't change the {$role->name} role: it allows things your own roles don't.", 403);
        }
        if ($role->is_system && isset($input['name']) && $input['name'] !== $role->name) {
            return $this->refuse('BUILT_IN', "The {$role->name} role is built in. It can be edited, and not renamed.", 409);
        }
        if (array_key_exists('grants', $input)) {
            if ($role->name === Role::ADMIN && $role->is_system && ! in_array('*', $input['grants'], true)) {
                return $this->refuse('BUILT_IN', 'The ADMIN role always allows everything. Make another role for less.', 409);
            }
            if ($refused = $this->beyondCaller($request, $input['grants'])) {
                return $refused;
            }
        }

        $role->fill(array_intersect_key($input, array_flip(['name', 'description', 'grants'])))->save();
        Access::forget();

        return response()->json(self::present($role, $this->users($role), $held));
    }

    /** DELETE _nevela/roles/{id} */
    public function destroy(Request $request, string $id): JsonResponse|Response
    {
        Gate::authorize('roles.delete');
        $role = $this->find($id);
        if ($role->is_system) {
            return $this->refuse('BUILT_IN', "The {$role->name} role is built in and can't be deleted.", 409);
        }
        if (Permissions::beyond(Access::grantsFor($request->user()), $role->grants) !== []) {
            return $this->refuse('BEYOND_YOUR_OWN', "You can't delete the {$role->name} role: it allows things your own roles don't.", 403);
        }
        // Deleting it would quietly take its permissions from everyone who holds it.
        if (($users = $this->users($role)) > 0) {
            return $this->refuse('IN_USE', $users === 1 ? 'One user has this role. Give them another before deleting it.' : "{$users} users have this role. Give them another before deleting it.", 409);
        }
        $role->delete();
        Access::forget();

        return response()->noContent();
    }

    private function find(string $id): Role
    {
        return Role::query()->find($id) ?? throw new NotFoundHttpException('No such role.');
    }

    private function users(Role $role): int
    {
        return DB::table('nevela_role_user')->where('role_id', $role->id)->count();
    }

    /** @return array{name?: string, description?: string|null, grants?: list<string>} */
    private function validated(Request $request, ?Role $role): array
    {
        $sometimes = $role ? ['sometimes'] : [];
        $input = $request->validate([
            'name' => [...$sometimes, 'required', 'string', 'min:2', 'max:80', 'regex:/^[\pL\pN][\pL\pN _-]*$/u', function (string $attribute, mixed $value, \Closure $fail) use ($role) {
                // "Support" and "support" would be two roles nobody could tell apart.
                $taken = Role::query()->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])->when($role, fn ($query) => $query->where('id', '!=', $role->id))->exists();
                if ($taken) {
                    $fail('There is already a role with this name.');
                }
            }],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'grants' => [...$sometimes, 'present', 'array', 'max:2000'],
            'grants.*' => ['string', 'max:120', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || ! Permissions::understood($value)) {
                    $fail("\"{$value}\" isn't a permission this app has.");
                }
            }],
        ], ['name.regex' => 'Use letters, numbers, spaces, dashes and underscores.']);
        if (isset($input['grants'])) {
            $input['grants'] = array_values(array_unique($input['grants']));
        }

        return $input;
    }

    /** @param list<string> $grants */
    private function beyondCaller(Request $request, array $grants): ?JsonResponse
    {
        $beyond = Permissions::beyond(Access::grantsFor($request->user()), $grants);
        if ($beyond === []) {
            return null;
        }

        return response()->json([
            'error' => "A role can't allow more than you hold yourself: ".implode(', ', array_slice($beyond, 0, 6)).(count($beyond) > 6 ? ', and '.(count($beyond) - 6).' more' : '').'.',
            'code' => 'BEYOND_YOUR_OWN',
            'beyond' => $beyond,
        ], 403);
    }

    /**
     * @param  list<string>  $held  The grants of whoever is asking
     * @return array<string, mixed>
     */
    public static function present(Role $role, int $users, array $held): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            // As written, patterns included, and what they come to today.
            'grants' => $role->grants,
            'permissions' => Permissions::expand($role->grants),
            'isSystem' => (bool) $role->is_system,
            'isAdmin' => Permissions::hasAll($role->grants),
            'users' => $users,
            // Whether whoever is asking may give this role to someone, or change it.
            'withinYours' => Permissions::beyond($held, $role->grants) === [],
        ];
    }
}
