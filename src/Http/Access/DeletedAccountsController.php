<?php

namespace Nevela\Laravel\Http\Access;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Nevela\Laravel\Access\ClosedAccounts;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Auth\AuthMail;
use Nevela\Laravel\Http\Auth\Answers;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Accounts that were closed: to restore, or to remove for good. And the emails whose
 * accounts were removed for good, which can't sign up again until one is allowed.
 *
 * All of it is for whoever may delete users (users.delete), under the same rule as the
 * Users screen: you act only on accounts that may do no more than you.
 */
final class DeletedAccountsController
{
    use Answers;
    use Ceiling;

    /** GET _nevela/deleted-accounts?q=&page=&perPage=: the latest closed first. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('users.delete');
        if ($refused = $this->notReady()) {
            return $refused;
        }
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $model = Account::model();
        $key = (new $model)->getKeyName();
        $query = ClosedAccounts::closed();
        if (($search = trim((string) ($input['q'] ?? ''))) !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
            $query->where(fn ($where) => $where->whereRaw('lower(name) like ?', [$pattern])->orWhereRaw('lower(email) like ?', [$pattern]));
        }

        $perPage = (int) ($input['perPage'] ?? 25);
        $page = (int) ($input['page'] ?? 1);
        $total = (clone $query)->count();
        $users = $query->orderByDesc('closed_at')->orderBy($key)->forPage($page, $perPage)->get();

        // Who closed each: themselves, or someone whose name is worth showing.
        $closers = $users->pluck('closed_by')->filter()->unique()->values()->all();
        $names = $closers === [] ? collect() : $model::query()->whereIn($key, $closers)->pluck('name', $key);
        $rows = UsersController::present($users, $request->user());
        foreach ($users->values() as $index => $user) {
            $by = $user->closed_by === null ? null : (string) $user->closed_by;
            $own = $by !== null && $by === (string) $user->getAuthIdentifier();
            $rows[$index] += [
                'closedAt' => $user->closed_at ? date(DATE_ATOM, strtotime((string) $user->closed_at)) : null,
                // "self": its owner closed it. "admin": someone deleted it from the Users screen.
                'closedBy' => $by === null ? null : ($own ? 'self' : 'admin'),
                'closedByName' => $by === null || $own ? null : ($names[$by] ?? null),
            ];
        }

        return response()->json([
            'data' => $rows,
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))],
            'blocked' => DB::table(ClosedAccounts::BLOCKED)->whereNull('user_id')->count(),
        ]);
    }

    /** POST _nevela/deleted-accounts/{id}/restore: open it again, as it was. */
    public function restore(Request $request, string $id): JsonResponse
    {
        Gate::authorize('users.delete');
        if ($refused = $this->notReady()) {
            return $refused;
        }
        $user = $this->find($id);
        if ($refused = $this->aboveCaller($request->user(), $user, 'restore')) {
            return $refused;
        }
        ClosedAccounts::restore($user);
        AuthMail::send($user->email, 'Your '.AuthMail::app().' account is open again', ['Your account has been restored, with everything it had.', 'Sign in the way you did before: your password is the one you last used.']);

        return response()->json(UsersController::present(collect([$user->refresh()]), $request->user())[0]);
    }

    /** DELETE _nevela/deleted-accounts/{id}: remove it for good. Its email stays blocked. */
    public function destroy(Request $request, string $id): JsonResponse|Response
    {
        Gate::authorize('users.delete');
        if ($refused = $this->notReady()) {
            return $refused;
        }
        $user = $this->find($id);
        if ($refused = $this->aboveCaller($request->user(), $user, 'remove')) {
            return $refused;
        }
        ClosedAccounts::purge($user);

        return response()->noContent();
    }

    /** GET _nevela/blocked-emails?page=&perPage=: emails whose accounts were removed for good. */
    public function blocked(Request $request): JsonResponse
    {
        Gate::authorize('users.delete');
        if ($refused = $this->notReady()) {
            return $refused;
        }
        $input = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $perPage = (int) ($input['perPage'] ?? 25);
        $page = (int) ($input['page'] ?? 1);
        $query = DB::table(ClosedAccounts::BLOCKED)->whereNull('user_id');
        $total = (clone $query)->count();

        return response()->json([
            'data' => $query->orderByDesc('blocked_at')->orderBy('id')->forPage($page, $perPage)->get()->map(fn ($row) => [
                'id' => (string) $row->id,
                'hint' => $row->hint,
                'blockedAt' => date(DATE_ATOM, strtotime((string) $row->blocked_at)),
            ])->all(),
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $perPage))],
        ]);
    }

    /** DELETE _nevela/blocked-emails/{id}: let that email have an account again. */
    public function allow(string $id): JsonResponse|Response
    {
        Gate::authorize('users.delete');
        if ($refused = $this->notReady()) {
            return $refused;
        }
        if (! ClosedAccounts::allow($id)) {
            throw new NotFoundHttpException('No such blocked email.');
        }

        return response()->noContent();
    }

    private function find(string $id): mixed
    {
        return ClosedAccounts::closed()->find($id) ?? throw new NotFoundHttpException('No such deleted account.');
    }

    /** An upgraded app keeps no deleted accounts until it has run its migrations. */
    private function notReady(): ?JsonResponse
    {
        return ClosedAccounts::ready() ? null : $this->refuse('MIGRATION_NEEDED', 'Deleted accounts need a migration that has not been run: php nevela migrate', 409);
    }
}
