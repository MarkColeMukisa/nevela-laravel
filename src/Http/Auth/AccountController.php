<?php

namespace Nevela\Laravel\Http\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Nevela\Laravel\Access\Access;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Auth\AuthMail;
use Nevela\Laravel\Auth\Challenges;
use Nevela\Laravel\Auth\Web;
use Nevela\Laravel\Http\UploadController;
use Nevela\Laravel\Models\Upload;
use Nevela\Laravel\Rules\UploadKey;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;

/**
 * An account and what its owner can do with it: create it, prove the email address,
 * change the name, the picture and the password, and see and sign out its devices.
 */
final class AccountController
{
    use Answers;

    private const VERIFY_SECONDS = 3600;

    private const RESET_SECONDS = 3600;

    /** POST auth/register */
    public function register(Request $request): JsonResponse
    {
        $this->enabled('registration');
        $model = Account::model();
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => Account::passwordRules(),
        ]);
        if (Account::findByEmail($input['email'])) {
            return $this->refuse('USER_ALREADY_EXISTS', 'An account with this email already exists. Sign in instead.', 422);
        }

        $user = new $model;
        $attributes = ['name' => $input['name'], 'email' => mb_strtolower(trim($input['email'])), 'password' => Hash::make($input['password'])];
        $role = config('nevela.auth.default_role', 'USER');
        if ($role !== null && Schema::hasColumn($user->getTable(), 'role')) {
            $attributes['role'] = $role;
        }
        $user->forceFill($attributes)->save();
        if (is_string($role) && $role !== '') {
            Access::grant($user, $role);
        }
        self::sendVerification($user, $request);

        if (config('nevela.auth.require_email_verification', false)) {
            return response()->json(['verify' => true, 'user' => Account::describe($user)], 201);
        }

        return response()->json(Account::signIn($user, $request), 201);
    }

    /** Email a code, and a link that carries it, to prove an address. */
    public static function sendVerification(mixed $user, Request $request, ?string $next = null): void
    {
        if (! Challenges::mayIssue('verify-email', mb_strtolower($user->email))) {
            return;
        }
        [$code, $hash] = Challenges::code();
        Challenges::put('verify-email', mb_strtolower($user->email), ['user' => (string) $user->getAuthIdentifier(), 'code' => $hash], self::VERIFY_SECONDS);
        $url = Web::url('/verify-email', ['email' => $user->email, 'code' => $code, 'next' => Web::path($next, '/dashboard/account')], $request);
        AuthMail::send($user->email, 'Verify your email for '.AuthMail::app(), ["Your verification code is {$code}.", 'Or use the button. Both expire in an hour.'], ['Verify email', $url], "{$code}  {$url}");
    }

    /** POST auth/email/send: send the verification email again. */
    public function resendVerification(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => ['nullable', 'email'], 'next' => ['nullable', 'string', 'max:2000']]);
        $user = $request->user('sanctum') ?? (isset($input['email']) ? Account::findByEmail($input['email']) : null);
        if ($user && $user->email_verified_at === null) {
            self::sendVerification($user, $request, $input['next'] ?? null);
        }

        return response()->json(['sent' => true]);
    }

    /** POST auth/email/verify */
    public function verifyEmail(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => ['required', 'email'], 'code' => ['required', 'string', 'max:16']]);
        $id = mb_strtolower(trim($input['email']));
        $pending = Challenges::get('verify-email', $id);
        if ($pending === null) {
            return $this->refuse('OTP_EXPIRED', 'That code has expired. Ask for a new one.', 401);
        }
        if (! hash_equals($pending['code'], Challenges::hash($input['code']))) {
            return Challenges::miss('verify-email', $id)
                ? $this->refuse('INVALID_OTP', "That code isn't right. Check it and try again.", 401)
                : $this->refuse('TOO_MANY_ATTEMPTS', 'Too many attempts. Ask for a new code.', 429);
        }
        Challenges::forget('verify-email', $id);
        $user = Account::model()::query()->find($pending['user']);
        if (! $user) {
            return $this->refuse('OTP_EXPIRED', 'That code has expired. Ask for a new one.', 401);
        }
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();

        // Someone verifying from the email on a new device is signed in there; someone
        // already signed in just gets their account back, verified.
        return $request->user('sanctum')
            ? response()->json(['user' => Account::describe($user)])
            : SignInController::finish($user, $request, byEmail: true);
    }

    /** PATCH auth/me: the name, and the picture (the key of an image uploaded with PUT auth/avatar). */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $input = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'avatar' => ['sometimes', 'nullable', 'string', 'max:512', new UploadKey('User', 'avatar')],
        ]);
        if (array_key_exists('avatar', $input) && $input['avatar'] !== null) {
            // Only a picture this person uploaded: a key is not a secret between users.
            $own = Upload::query()->where('key', $input['avatar'])->where('uploaded_by', (string) $user->getAuthIdentifier())->exists();
            if (! $own) {
                return $this->refuse('INVALID_AVATAR', 'Upload the picture again.', 422);
            }
        }
        if (array_key_exists('avatar', $input) && ! Schema::hasColumn($user->getTable(), 'avatar')) {
            return $this->refuse('MIGRATION_NEEDED', 'Profile pictures need a migration that has not been run: php artisan migrate', 409);
        }
        $user->forceFill($input)->save();

        return response()->json(['user' => Account::describe($user)]);
    }

    /**
     * PUT auth/avatar?name=me.jpg: the picture is the request body. It goes through the
     * same optimiser as any image field, with the "avatar" profile: a 400×400 square
     * and an 80×80 thumbnail. The account is pointed at it straight away.
     */
    public function avatar(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! Schema::hasColumn($user->getTable(), 'avatar')) {
            return $this->refuse('MIGRATION_NEEDED', 'Profile pictures need a migration that has not been run: php artisan migrate', 409);
        }
        $field = new Field('avatar', 'file', required: false, accept: ['image'], profile: 'avatar');
        $upload = UploadController::receive($request, new Descriptor('User', ['avatar' => $field], $user->getTable(), 'users', 'User', 'Users'), $field);
        if ($upload instanceof JsonResponse) {
            return $upload;
        }
        $user->forceFill(['avatar' => $upload->key])->save();

        return response()->json(['user' => Account::describe($user)], 201);
    }

    /** POST auth/password: change it, knowing the current one. */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $input = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => Account::passwordRules(),
            'revokeOtherSessions' => ['nullable', 'boolean'],
        ]);
        if (! Hash::check($input['currentPassword'], (string) $user->getAuthPassword())) {
            return $this->refuse('INVALID_PASSWORD', "That isn't your current password.", 422);
        }
        $user->forceFill(['password' => Hash::make($input['newPassword'])])->save();
        if ($input['revokeOtherSessions'] ?? false) {
            Account::revokeTokens($user, $user->currentAccessToken()?->getKey());
        }
        AuthMail::send($user->email, 'Your '.AuthMail::app().' password was changed', ['The password for your account was just changed.', "If that wasn't you, reset it now and check the devices signed in to your account."]);

        return response()->json(['changed' => true]);
    }

    /** POST auth/password/forgot: email a link to choose a new password. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => ['required', 'email']]);
        if ($user = Account::findByEmail($input['email'])) {
            $token = Challenges::start('reset-password', ['user' => (string) $user->getAuthIdentifier()], self::RESET_SECONDS);
            $url = Web::url('/reset-password', ['token' => $token], $request);
            AuthMail::send($user->email, 'Reset your '.AuthMail::app().' password', ['Use this link to choose a new password. It works once and expires in an hour.'], ['Choose a new password', $url], $url);
        }

        return response()->json(['sent' => true]);
    }

    /** POST auth/password/reset */
    public function resetPassword(Request $request): JsonResponse
    {
        $input = $request->validate(['token' => ['required', 'string'], 'newPassword' => Account::passwordRules()]);
        $pending = Challenges::take('reset-password', $input['token']);
        $user = $pending ? Account::model()::query()->find($pending['user']) : null;
        if (! $user) {
            return $this->refuse('INVALID_TOKEN', 'That reset link has expired or was already used. Ask for a new one.', 401);
        }
        // Whoever opened the link owns the address, and every device is signed out:
        // a reset is what someone does when they think another person has got in.
        $user->forceFill(['password' => Hash::make($input['newPassword']), 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        Account::revokeTokens($user);

        return response()->json(['reset' => true]);
    }

    /** GET auth/sessions: the devices signed in to this account. */
    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken()?->getKey();
        $tracked = Schema::hasColumn('personal_access_tokens', 'user_agent');
        $rows = DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->getAuthIdentifier())
            ->orderByDesc('last_used_at')->orderByDesc('created_at')->get();

        return response()->json([
            'current' => $current === null ? null : (string) $current,
            'data' => $rows->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => $row->name,
                'userAgent' => $tracked ? $row->user_agent : null,
                'ipAddress' => $tracked ? $row->ip_address : null,
                'createdAt' => $row->created_at ? date(DATE_ATOM, strtotime($row->created_at)) : null,
                'lastUsedAt' => $row->last_used_at ? date(DATE_ATOM, strtotime($row->last_used_at)) : null,
                'current' => (string) $row->id === (string) $current,
            ])->all(),
        ]);
    }

    /** DELETE auth/sessions/{id}: sign one device out. */
    public function revokeSession(Request $request, string $id): Response
    {
        $user = $request->user();
        DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->getAuthIdentifier())
            ->where('id', $id)->delete();

        return response()->noContent();
    }

    /** DELETE auth/sessions: sign every other device out. */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['revoked' => Account::revokeTokens($user, $user->currentAccessToken()?->getKey())]);
    }
}
