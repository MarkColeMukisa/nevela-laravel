<?php

namespace Nevela\Laravel\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
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

        return [
            'id' => (string) $user->getAuthIdentifier(),
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
            'role' => $user->role ?? null,
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
        $ip = (string) $request->header('X-Nevela-Ip', '');
        DB::table('personal_access_tokens')->where('id', $tokenId)->update([
            'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $request->ip(),
            'user_agent' => mb_substr((string) ($request->header('X-Nevela-User-Agent') ?: $request->userAgent()), 0, 512) ?: null,
        ]);
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
