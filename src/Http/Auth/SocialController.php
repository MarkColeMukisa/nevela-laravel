<?php

namespace Nevela\Laravel\Http\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Access\ClosedAccounts;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Auth\Challenges;
use Nevela\Laravel\Auth\Social;
use Nevela\Laravel\Auth\Web;
use Nevela\Laravel\Support\Key;
use Nevela\Laravel\Support\SchemaFacts;
use Throwable;

/**
 * Signing in with Google or GitHub, and connecting them to an account (see Auth\Social).
 *
 *   POST auth/oauth/{provider}/start     → { url, state }   where to send the browser
 *   POST auth/oauth/{provider}/callback  { state, code }    → a token, a second step, or { linked }
 *   GET  auth/social                     the signed-in person's connected providers
 *   DELETE auth/social/{id}              disconnect one
 *
 * The first two are called by the dashboard's server, never by the browser.
 *
 * Who may come in this way follows `registration`. Off, only someone who already has an
 * account: matched by the provider account connected to it, or by an address the provider
 * says is verified. On, anyone else gets a new account as well.
 */
final class SocialController
{
    use Answers;

    /** How long the provider's page may take: the state is good for this long, once. */
    private const STATE_SECONDS = 600;

    private const TABLE = 'nevela_social_accounts';

    public function start(Request $request, string $provider): JsonResponse
    {
        if (($refused = $this->unavailable($provider)) !== null) {
            return $refused;
        }
        $input = $request->validate(['next' => ['nullable', 'string', 'max:2000'], 'link' => ['nullable', 'boolean']]);
        $linking = (bool) ($input['link'] ?? false);
        $user = $linking ? $request->user('sanctum') : null;
        if ($linking && $user === null) {
            return $this->refuse('UNAUTHENTICATED', 'Sign in first, then connect it from your account.', 401);
        }

        $callback = Social::callback($provider, $request);
        $state = Challenges::start('oauth', [
            'provider' => $provider,
            'callback' => $callback,
            'next' => Web::path($input['next'] ?? null, $linking ? '/dashboard/account/security' : '/dashboard'),
            'user' => $user === null ? null : (string) $user->getAuthIdentifier(),
            'device' => $request->input('deviceName'),
        ], self::STATE_SECONDS);

        return response()->json(['url' => Social::authorizationUrl($provider, $callback, $state), 'state' => $state]);
    }

    public function callback(Request $request, string $provider): JsonResponse
    {
        if (($refused = $this->unavailable($provider)) !== null) {
            return $refused;
        }
        // Good once, and only for the provider it was made for: a callback can't be replayed,
        // and can't be one that this app didn't start.
        $pending = Challenges::take('oauth', (string) $request->input('state'));
        if ($pending === null || ($pending['provider'] ?? null) !== $provider) {
            return $this->refuse('OAUTH_STATE', 'That sign-in took too long or was already used. Start again.', 401);
        }
        $next = (string) ($pending['next'] ?? '/dashboard');
        $label = Social::LABELS[$provider];
        if ($request->filled('error')) {
            return $this->refuse('OAUTH_DENIED', "{$label} sign-in was cancelled.", 401, ['next' => $next]);
        }
        $code = (string) $request->input('code');
        if ($code === '') {
            return $this->refuse('OAUTH_FAILED', "{$label} didn't say who you are. Try again.", 401, ['next' => $next]);
        }

        try {
            $profile = Social::profile($provider, (string) $pending['callback'], $code);
        } catch (Throwable $e) {
            report($e);

            return $this->refuse('OAUTH_FAILED', "{$label} sign-in didn't finish. Try again.", 401, ['next' => $next]);
        }
        if ($profile['email'] === null) {
            return $this->refuse('NO_EMAIL', "Your {$label} account didn't share an email address, so it can't be matched to an account.", 422, ['next' => $next]);
        }

        // Connecting it to the account that asked, from the Account page.
        if (($pending['user'] ?? null) !== null) {
            return $this->connect($provider, $profile, (string) $pending['user'], $next);
        }

        $user = $this->linked($provider, $profile['id']);
        if ($user === null) {
            if (! $profile['verified']) {
                return $this->refuse('EMAIL_NOT_VERIFIED', "Verify your email address with {$label} first, then try again.", 422, ['next' => $next]);
            }
            $user = Account::findByEmail($profile['email']);
            if ($user === null) {
                $made = $this->register($profile);
                if ($made instanceof JsonResponse) {
                    return $made;
                }
                $user = $made;
            } elseif ($user->email_verified_at === null) {
                $this->reclaim($user);
            }
            if (! $this->link($provider, $profile, $user)) {
                return $this->refuse('ALREADY_LINKED', "That {$label} account is connected to another account here.", 409, ['next' => $next]);
            }
        }
        // The provider has seen this address work.
        if ($profile['verified'] && $user->email_verified_at === null && mb_strtolower((string) $user->email) === $profile['email']) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $request->merge(['deviceName' => $pending['device'] ?? null]);
        // The same as after a password: an account with a second step still owes it.
        $answer = SignInController::finish($user, $request);
        $answer->setData(['next' => $next] + (array) $answer->getData(true));

        return $answer;
    }

    /** GET auth/social: the providers connected to the signed-in account. */
    public function accounts(Request $request): JsonResponse
    {
        if (! SchemaFacts::hasTable(self::TABLE)) {
            return response()->json(['data' => []]);
        }
        $rows = DB::table(self::TABLE)->where('user_id', (string) $request->user()->getAuthIdentifier())->orderBy('created_at')->get();

        return response()->json(['data' => $rows->map(fn ($row) => [
            'id' => (string) $row->id,
            'provider' => (string) $row->provider,
            'email' => $row->email,
            'connectedAt' => $row->created_at,
        ])->all()]);
    }

    /**
     * DELETE auth/social/{id}. Always allowed: every account has an email address, and so a
     * way back in by password reset, a sign-in link or a code, whichever are switched on.
     */
    public function disconnect(Request $request, string $id): Response
    {
        if (Key::isUuid($id) && SchemaFacts::hasTable(self::TABLE)) {
            DB::table(self::TABLE)->where('id', $id)->where('user_id', (string) $request->user()->getAuthIdentifier())->delete();
        }

        return response()->noContent();
    }

    /** Refused when the switch is off, the provider is unknown, or it has no keys. */
    private function unavailable(string $provider): ?JsonResponse
    {
        return in_array($provider, Social::available(), true)
            ? null
            : $this->refuse('NOT_ENABLED', "Signing in with that provider isn't switched on for this app.", 404);
    }

    /** The account a provider's account is connected to. */
    private function linked(string $provider, string $providerId): mixed
    {
        if (! SchemaFacts::hasTable(self::TABLE)) {
            return null;
        }
        $row = DB::table(self::TABLE)->where('provider', $provider)->where('provider_id', $providerId)->first();
        if ($row === null) {
            return null;
        }
        $user = Account::model()::query()->find($row->user_id);
        // Connected to an account that is gone: the connection goes too, so it can be made again.
        if ($user === null) {
            DB::table(self::TABLE)->where('id', $row->id)->delete();
        }

        return $user;
    }

    /**
     * Connect a provider account to this one. False when it is connected to another account,
     * as when two requests raced to connect it.
     *
     * @param  array{id: string, email: string|null, verified: bool, name: string|null}  $profile
     */
    private function link(string $provider, array $profile, mixed $user): bool
    {
        if (! SchemaFacts::hasTable(self::TABLE)) {
            return true; // not migrated yet: matched by address every time until it is
        }
        $id = (string) $user->getAuthIdentifier();
        DB::table(self::TABLE)->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'user_id' => $id,
            'provider' => $provider,
            'provider_id' => $profile['id'],
            'email' => $profile['email'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Whichever row is there now is the answer.
        return DB::table(self::TABLE)->where('provider', $provider)->where('provider_id', $profile['id'])->value('user_id') === $id;
    }

    /**
     * The provider has just proved this address belongs to whoever is signing in. Nobody had
     * proved it before: the account may have been made by someone else, in their name, with a
     * password of their own. So that password stops working and every device is signed out.
     * The owner signs in with the provider from here, or sets a password with "Forgot password".
     */
    private function reclaim(mixed $user): void
    {
        Account::revokeTokens($user);
        $user->forceFill(['password' => Hash::make(Str::random(48))])->save();
    }

    /** @param array{id: string, email: string|null, verified: bool, name: string|null} $profile */
    private function connect(string $provider, array $profile, string $userId, string $next): JsonResponse
    {
        $user = Account::model()::query()->find($userId);
        if ($user === null) {
            return $this->refuse('UNAUTHENTICATED', 'Sign in again, then connect it.', 401, ['next' => $next]);
        }
        $holder = $this->linked($provider, $profile['id']);
        if (($holder !== null && (string) $holder->getAuthIdentifier() !== $userId) || ! $this->link($provider, $profile, $user)) {
            return $this->refuse('ALREADY_LINKED', 'That '.Social::LABELS[$provider].' account is connected to another account here.', 409, ['next' => $next]);
        }

        return response()->json(['linked' => true, 'provider' => $provider, 'next' => $next]);
    }

    /**
     * A new account, where people may make their own: as `register` makes one, with the
     * address already proved and a password nobody knows. Its owner signs in with the
     * provider, or sets a password with "Forgot password".
     *
     * @param  array{id: string, email: string|null, verified: bool, name: string|null}  $profile
     */
    private function register(array $profile): mixed
    {
        if (! config('nevela.auth.registration', false)) {
            return $this->refuse('NO_ACCOUNT', "There's no account here for {$profile['email']}. Ask an administrator for one.", 403);
        }
        $standing = ClosedAccounts::standing((string) $profile['email']);
        if ($standing === 'closed') {
            return $this->refuse('ACCOUNT_CLOSED', 'An account with this email was closed. Ask an administrator to restore it.', 403);
        }
        if ($standing === 'blocked') {
            return $this->refuse('EMAIL_BLOCKED', "This email can't be used for a new account. Ask an administrator.", 403);
        }

        $model = Account::model();
        $user = new $model;
        $attributes = [
            'name' => $profile['name'] ?: Str::before((string) $profile['email'], '@'),
            'email' => $profile['email'],
            'password' => Hash::make(Str::random(48)),
            'email_verified_at' => now(),
        ];
        $role = config('nevela.auth.default_role', 'USER');
        if ($role !== null && SchemaFacts::hasColumn($user->getTable(), 'role')) {
            $attributes['role'] = $role;
        }
        $user->forceFill($attributes)->save();
        if (is_string($role) && $role !== '') {
            Access::grant($user, $role);
        }

        return $user;
    }
}
