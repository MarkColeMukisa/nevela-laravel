<?php

namespace Nevela\Laravel\Http\Access;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Access\Permissions;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Auth\AuthMail;
use Nevela\Laravel\Http\Auth\Answers;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The people who can sign in, for someone allowed to manage them.
 *
 * Three rules sit on top of the permissions (users.view, .create, .edit, .delete), so that
 * managing users is never a way to take more than you were given:
 *
 *   - you hand out only roles whose grants you hold yourself;
 *   - only an administrator changes an administrator's account;
 *   - the last administrator can't be removed, switched off or demoted, by anyone.
 */
final class UsersController
{
    use Answers;

    /** GET _nevela/users?q=&role=&status=&page=&perPage= */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('users.view');
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'role' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $model = Account::model();
        $instance = new $model;
        $query = $model::query();

        if (($search = trim((string) ($input['q'] ?? ''))) !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(fn ($where) => $where->whereRaw('lower(name) like ?', [$pattern])->orWhereRaw('lower(email) like ?', [$pattern]));
        }
        if (! empty($input['role'])) {
            // Read as a list first: the link table keeps ids as text, whatever type they are here.
            $query->whereIn($instance->getKeyName(), DB::table('nevela_role_user')->where('role_id', $input['role'])->pluck('user_id')->all());
        }
        if (isset($input['status']) && Schema::hasColumn($instance->getTable(), 'active')) {
            $query->where('active', $input['status'] === 'active');
        }

        $perPage = (int) ($input['perPage'] ?? 25);
        $page = (int) ($input['page'] ?? 1);
        $total = (clone $query)->count();
        $users = $query->orderBy('name')->orderBy($instance->getKeyName())->forPage($page, $perPage)->get();

        return response()->json([
            'data' => self::present($users, $request->user()),
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))],
        ]);
    }

    /** GET _nevela/users/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        Gate::authorize('users.view');

        return response()->json(self::present(collect([$this->find($id)]), $request->user())[0]);
    }

    /** POST _nevela/users */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('users.create');
        $model = Account::model();
        $user = new $model;
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => Account::passwordRules(),
            'roles' => ['present', 'array', 'max:50'],
            'roles.*' => ['string', 'max:64'],
            'active' => ['nullable', 'boolean'],
        ]);
        if (Account::findByEmail($input['email'])) {
            return response()->json(['error' => 'Validation failed.', 'issues' => [['path' => 'email', 'message' => 'Someone already has this email.']]], 422);
        }
        $roles = $this->roles($input['roles']);
        if ($roles instanceof JsonResponse) {
            return $roles;
        }
        if ($refused = $this->beyondCaller($request, $roles)) {
            return $refused;
        }

        $attributes = ['name' => $input['name'], 'email' => mb_strtolower(trim($input['email'])), 'password' => Hash::make($input['password'])];
        if (Schema::hasColumn($user->getTable(), 'active')) {
            $attributes['active'] = (bool) ($input['active'] ?? true);
        }
        if (Schema::hasColumn($user->getTable(), 'email_verified_at')) {
            // Someone allowed to create users vouches for the address.
            $attributes['email_verified_at'] = now();
        }
        $user->forceFill($attributes)->save();
        Access::assign($user, $roles->pluck('id')->all());

        return response()->json(self::present(collect([$user]), $request->user())[0], 201);
    }

    /** PATCH _nevela/users/{id}: any of name, email, password, roles, active. */
    public function update(Request $request, string $id): JsonResponse
    {
        Gate::authorize('users.edit');
        $user = $this->find($id);
        $caller = $request->user();
        $self = (string) $caller->getAuthIdentifier() === (string) $user->getAuthIdentifier();
        $input = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'password' => ['sometimes', ...Account::passwordRules()],
            'roles' => ['sometimes', 'array', 'max:50'],
            'roles.*' => ['string', 'max:64'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $wasAdmin = $this->holdsEverything($user);
        if ($wasAdmin && ! Access::isAdmin($caller)) {
            return $this->refuse('ADMIN_ONLY', "Only an administrator can change an administrator's account.", 403);
        }
        if (isset($input['email'])) {
            $taken = Account::findByEmail($input['email']);
            if ($taken && (string) $taken->getAuthIdentifier() !== (string) $user->getAuthIdentifier()) {
                return response()->json(['error' => 'Validation failed.', 'issues' => [['path' => 'email', 'message' => 'Someone already has this email.']]], 422);
            }
        }

        $roles = null;
        if (array_key_exists('roles', $input)) {
            $roles = $this->roles($input['roles']);
            if ($roles instanceof JsonResponse) {
                return $roles;
            }
            // Only what is being added is checked: a role they already hold isn't being handed out.
            $held = Access::rolesOf($user)->pluck('id')->all();
            if ($refused = $this->beyondCaller($request, $roles->reject(fn (Role $role) => in_array($role->id, $held, true)))) {
                return $refused;
            }
        }

        $deactivating = array_key_exists('active', $input) && ! $input['active'] && Access::active($user);
        if ($deactivating && $self) {
            return $this->refuse('SELF', "You can't switch your own account off. Ask another administrator.", 409);
        }
        $demoting = $roles !== null && $wasAdmin && ! $roles->contains(fn (Role $role) => Permissions::hasAll($role->grants));
        if (($deactivating || $demoting) && $wasAdmin && Access::active($user) && Access::otherAdmins($user) === 0) {
            return $this->refuse('LAST_ADMIN', 'This is the only administrator. Make someone else an administrator first.', 409);
        }

        $attributes = [];
        if (isset($input['name'])) {
            $attributes['name'] = $input['name'];
        }
        if (isset($input['email'])) {
            $attributes['email'] = mb_strtolower(trim($input['email']));
        }
        if (isset($input['password'])) {
            $attributes['password'] = Hash::make($input['password']);
        }
        if (array_key_exists('active', $input) && Schema::hasColumn($user->getTable(), 'active')) {
            $attributes['active'] = (bool) $input['active'];
        }
        if ($attributes !== []) {
            $user->forceFill($attributes)->save();
        }
        if ($roles !== null) {
            Access::assign($user, $roles->pluck('id')->all());
        }

        // A new password or a switched-off account ends every session it had. Their own
        // session survives their own password change, as it does on the account page.
        if ($deactivating || isset($input['password'])) {
            Account::revokeTokens($user, $self ? $caller->currentAccessToken()?->getKey() : null);
        }
        if (isset($input['password']) && ! $self) {
            AuthMail::send($user->email, 'Your '.AuthMail::app().' password was changed', ['An administrator set a new password for your account.', "If you weren't expecting this, ask them about it before signing in."]);
        }
        Access::forget();

        return response()->json(self::present(collect([$user->refresh()]), $caller)[0]);
    }

    /** DELETE _nevela/users/{id} */
    public function destroy(Request $request, string $id): JsonResponse|Response
    {
        Gate::authorize('users.delete');
        $user = $this->find($id);
        $caller = $request->user();
        if ((string) $caller->getAuthIdentifier() === (string) $user->getAuthIdentifier()) {
            return $this->refuse('SELF', "You can't delete your own account here.", 409);
        }
        if ($this->holdsEverything($user)) {
            if (! Access::isAdmin($caller)) {
                return $this->refuse('ADMIN_ONLY', "Only an administrator can delete an administrator's account.", 403);
            }
            if (Access::active($user) && Access::otherAdmins($user) === 0) {
                return $this->refuse('LAST_ADMIN', 'This is the only administrator. Make someone else an administrator first.', 409);
            }
        }

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

        return response()->noContent();
    }

    /** DELETE _nevela/users/{id}/sessions: sign them out of every device. */
    public function revokeSessions(Request $request, string $id): JsonResponse
    {
        Gate::authorize('users.edit');
        $user = $this->find($id);
        if ($this->holdsEverything($user) && ! Access::isAdmin($request->user())) {
            return $this->refuse('ADMIN_ONLY', "Only an administrator can sign an administrator out.", 403);
        }
        $self = (string) $request->user()->getAuthIdentifier() === (string) $user->getAuthIdentifier();

        return response()->json(['revoked' => Account::revokeTokens($user, $self ? $request->user()->currentAccessToken()?->getKey() : null)]);
    }

    private function find(string $id): mixed
    {
        return Account::model()::query()->find($id) ?? throw new NotFoundHttpException('No such user.');
    }

    /** Whether this account holds a role that comes to everything. */
    private function holdsEverything(mixed $user): bool
    {
        return Access::rolesOf($user)->contains(fn (Role $role) => Permissions::hasAll($role->grants));
    }

    /**
     * The roles named by id, or a 422 when one doesn't exist.
     *
     * @param  list<string>  $ids
     * @return Collection<int, Role>|JsonResponse
     */
    private function roles(array $ids): Collection|JsonResponse
    {
        $ids = array_values(array_unique($ids));
        $roles = Role::query()->whereIn('id', $ids)->get();
        if ($roles->count() !== count($ids)) {
            return response()->json(['error' => 'Validation failed.', 'issues' => [['path' => 'roles', 'message' => 'One of those roles no longer exists. Reload and try again.']]], 422);
        }

        return $roles;
    }

    /** @param Collection<int, Role> $roles */
    private function beyondCaller(Request $request, Collection $roles): ?JsonResponse
    {
        $held = Access::grantsFor($request->user());
        foreach ($roles as $role) {
            if (Permissions::beyond($held, $role->grants) !== []) {
                return $this->refuse('BEYOND_YOUR_OWN', "You can't give someone the {$role->name} role: it allows things your own roles don't.", 403);
            }
        }

        return null;
    }

    /**
     * Users as the dashboard shows them, with what a list needs asked for once and not per row.
     *
     * @param  Collection<int, mixed>  $users
     * @return list<array<string, mixed>>
     */
    public static function present(Collection $users, mixed $caller): array
    {
        $ids = $users->map(fn ($user) => (string) $user->getAuthIdentifier())->all();
        if ($ids === []) {
            return [];
        }
        $roles = Role::query()->get()->keyBy('id');
        $held = [];
        foreach (DB::table('nevela_role_user')->whereIn('user_id', $ids)->get() as $link) {
            if ($role = $roles->get($link->role_id)) {
                $held[$link->user_id][] = $role;
            }
        }
        $secondStep = Schema::hasTable('nevela_two_factor')
            ? DB::table('nevela_two_factor')->whereIn('user_id', $ids)->whereNotNull('enabled_at')->pluck('user_id')->flip()
            : collect();
        $first = $users->first();
        $devices = DB::table('personal_access_tokens')
            ->where('tokenable_type', $first->getMorphClass())->whereIn('tokenable_id', $ids)
            ->groupBy('tokenable_id')->selectRaw('tokenable_id, count(*) as devices, max(last_used_at) as last_used_at')
            ->get()->keyBy(fn ($row) => (string) $row->tokenable_id);

        return $users->map(function ($user) use ($held, $secondStep, $devices, $caller) {
            $id = (string) $user->getAuthIdentifier();
            $mine = collect($held[$id] ?? [])->sortBy('name')->values();
            $avatar = $user->avatar ?? null;
            $file = is_string($avatar) && $avatar !== '' ? Uploads::ref($avatar) : null;
            $seen = $devices->get($id);

            return [
                'id' => $id,
                'name' => $user->name ?? null,
                'email' => $user->email ?? null,
                'active' => Access::active($user),
                'emailVerified' => ($user->email_verified_at ?? null) !== null,
                'twoFactorEnabled' => $secondStep->has($id),
                'image' => $file ? ($file['renditions']->thumb['url'] ?? $file['url']) : null,
                'roles' => $mine->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])->all(),
                'isAdmin' => $mine->contains(fn (Role $role) => Permissions::hasAll($role->grants)),
                'isSelf' => $id === (string) $caller->getAuthIdentifier(),
                'devices' => (int) ($seen->devices ?? 0),
                'lastActiveAt' => ! empty($seen?->last_used_at) ? date(DATE_ATOM, strtotime($seen->last_used_at)) : null,
                'createdAt' => $user->created_at?->toAtomString(),
            ];
        })->all();
    }
}
