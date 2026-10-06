<?php

namespace Nevela\Laravel\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Access\Permissions;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Models\TwoFactor;
use RuntimeException;

/**
 * The signed-in person, as the API describes them, and the tokens that are their devices.
 */
final class Account
{
    /** The app's user model. */
    public static function model(): string
    {
        return (string) config('auth.providers.users.model');
    }

    public static function findByEmail(string $email): mixed
    {
        return self::model()::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
    }

    /**
     * What a client needs to know about a user.
     *
     * @return array<string, mixed>
     */
    public static function describe(mixed $user): array
    {
        $avatar = $user->avatar ?? null;
        $file = is_string($avatar) && $avatar !== '' ? Uploads::ref($avatar) : null;
        $roles = Access::ready() ? Access::rolesOf($user)->pluck('name')->all() : [];
        $grants = Access::grantsFor($user);

        return [
            'id' => (string) $user->getAuthIdentifier(),
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
            // The first role, for code written when an account had one; "roles" has them all.
            'role' => $roles[0] ?? ($user->role ?? null),
            'roles' => $roles,
            // Everything they may do, patterns already resolved, so a client only looks a
            // permission up. Laravel decides each request for itself whatever a client shows.
            'permissions' => Permissions::expand($grants),
            'isAdmin' => Permissions::hasAll($grants),
            'emailVerified' => ($user->email_verified_at ?? null) !== null,
            'twoFactorEnabled' => self::twoFactor($user)?->enabled_at !== null,
            // The key is what is stored; "image" is the small rendition, ready for an <img>.
            'avatar' => $file ? $avatar : null,
            'avatarFile' => $file,
            'image' => $file ? ($file['renditions']->thumb['url'] ?? $file['url']) : null,
        ];
    }

    public static function twoFactor(mixed $user): ?TwoFactor
    {
        return TwoFactor::query()->where('user_id', (string) $user->getAuthIdentifier())->first();
    }

    /**
     * Sign someone in: a new Sanctum token, remembered with the browser it was made for.
     *
     * @return array{token: string, user: array<string, mixed>}
     */
    public static function signIn(mixed $user, Request $request): array
    {
        // Every way of signing in ends here, so this is the one place a switched-off
        // account is turned away, whichever way it came.
        if (! Access::active($user)) {
            throw new HttpResponseException(response()->json(['error' => 'This account has been switched off. Ask an administrator.', 'code' => 'ACCOUNT_DISABLED'], 403));
        }
        if (! method_exists($user, 'createToken')) {
            throw new RuntimeException('Add Laravel\Sanctum\HasApiTokens to '.self::model().' to issue Nevela tokens.');
        }
        $name = (string) ($request->input('deviceName') ?: config('nevela.auth.token_name', 'nevela-web'));
        $token = $user->createToken(mb_substr($name, 0, 255));
        self::remember($token->accessToken->getKey(), $request);

        return ['token' => $token->plainTextToken, 'user' => self::describe($user)];
    }

    /**
     * The browser's address and name. The dashboard's server makes the call to Laravel,
     * so it passes these on from the person's own request; a direct API client is itself.
     */
    private static function remember(mixed $tokenId, Request $request): void
    {
        if (! Schema::hasColumn('personal_access_tokens', 'user_agent')) {
            return; // the migration hasn't been run yet; signing in still works
        }
        $dashboard = self::fromDashboard($request);
        $ip = $dashboard ? (string) $request->header('X-Nevela-Ip', '') : '';
        $agent = $dashboard ? (string) $request->header('X-Nevela-User-Agent', '') : '';
        DB::table('personal_access_tokens')->where('id', $tokenId)->update([
            'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $request->ip(),
            'user_agent' => mb_substr($agent !== '' ? $agent : (string) $request->userAgent(), 0, 512) ?: null,
        ]);
    }

    /**
     * Whether a request comes from the dashboard's server, and so may speak for a browser.
     *
     * Anyone can call the API directly and send the same headers, so they are believed only
     * with the secret the dashboard and Laravel share (NEVELA_PROXY_SECRET in both). Without
     * one configured they are believed from this machine while the app is in development,
     * and otherwise not at all: a device then shows the address the request really came from.
     */
    public static function fromDashboard(Request $request): bool
    {
        $secret = config('nevela.auth.proxy_secret');
        if (is_string($secret) && $secret !== '') {
            return hash_equals($secret, (string) $request->header('X-Nevela-Proxy-Secret', ''));
        }

        return app()->environment('local') && in_array($request->ip(), ['127.0.0.1', '::1'], true);
    }

    /**
     * The rules a new password has to meet. Length is what is required; the dashboard
     * shows the rest as advice. A password found in a breach is refused, because it is
     * in the lists attackers try first however strong it looks.
     *
     * @return list<mixed>
     */
    public static function passwordRules(): array
    {
        $rule = Password::min(8);
        if (config('nevela.auth.check_breached_passwords', true) && ! app()->runningUnitTests()) {
            $rule = $rule->uncompromised();
        }

        return ['required', 'string', 'max:128', $rule];
    }

    /** Sign every device out, except the one asking when `$except` is given. */
    public static function revokeTokens(mixed $user, mixed $except = null): int
    {
        $query = DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getAuthIdentifier());
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }

        return $query->delete();
    }
}
