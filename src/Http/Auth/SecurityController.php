<?php

namespace Nevela\Laravel\Http\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Nevela\Laravel\Auth\Account;
use Nevela\Laravel\Auth\AuthMail;
use Nevela\Laravel\Auth\Challenges;
use Nevela\Laravel\Auth\Passkeys;
use Nevela\Laravel\Auth\Totp;
use Nevela\Laravel\Models\Passkey;
use Nevela\Laravel\Models\TwoFactor;
use RuntimeException;

/**
 * How a signed-in person protects their account: a second step for password sign-in,
 * and passkeys. Anything that weakens the account takes the password again.
 */
final class SecurityController
{
    use Answers;

    private const BACKUP_CODES = 10;

    /**
     * POST auth/two-factor/enable
     *
     * With `method: "totp"` this starts setting up an authenticator app: it answers with
     * the secret (as the address a QR code holds) and backup codes, and nothing is
     * switched on until a code from the app is confirmed. With `method: "email"` codes
     * are emailed at sign-in, and it is on straight away.
     */
    public function enable(Request $request): JsonResponse
    {
        $input = $request->validate(['password' => ['required', 'string'], 'method' => ['nullable', 'in:totp,email,otp']]);
        if (! $this->passwordConfirmed($request)) {
            return $this->refuse('INVALID_PASSWORD', "That isn't your password.", 422);
        }
        $user = $request->user();
        $method = ($input['method'] ?? 'totp') === 'totp' ? 'totp' : 'email';
        $this->enabled($method === 'totp' ? 'two_factor.authenticator' : 'two_factor.email');

        [$codes, $hashes] = self::backupCodes();
        $second = TwoFactor::query()->firstOrNew(['user_id' => (string) $user->getAuthIdentifier()]);

        if ($method === 'email') {
            $second->fill(['backup_codes' => $hashes, 'enabled_at' => $second->enabled_at ?? now()])->save();

            return response()->json(['enabled' => true, 'backupCodes' => $codes]);
        }

        $secret = Totp::secret();
        // Not confirmed yet: sign-in keeps working as it did until a code proves the app has the secret.
        $second->fill(['secret' => $secret, 'totp_confirmed_at' => null, 'backup_codes' => $hashes])->save();

        return response()->json([
            'enabled' => false,
            'totpURI' => Totp::uri($secret, (string) $user->email, AuthMail::app()),
            'secret' => $secret,
            'backupCodes' => $codes,
        ]);
    }

    /** POST auth/two-factor/confirm: a code from the authenticator app switches it on. */
    public function confirm(Request $request): JsonResponse
    {
        $input = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $second = Account::twoFactor($request->user());
        if ($second?->secret === null) {
            return $this->refuse('TWO_FACTOR_NOT_STARTED', 'Start setting up two-factor first.', 409);
        }
        // Spent like any other code, so the one that confirmed the app can't then sign in with it.
        if (! $second->spendCode($input['code'])) {
            return $this->refuse('INVALID_TWO_FACTOR_CODE', "That code isn't right. Check it and try again.", 422);
        }
        $second->fill(['totp_confirmed_at' => now(), 'enabled_at' => $second->enabled_at ?? now()])->save();

        return response()->json(['enabled' => true, 'user' => Account::describe($request->user())]);
    }

    /** POST auth/two-factor/disable */
    public function disable(Request $request): JsonResponse
    {
        if (! $this->passwordConfirmed($request)) {
            return $this->refuse('INVALID_PASSWORD', "That isn't your password.", 422);
        }
        TwoFactor::query()->where('user_id', (string) $request->user()->getAuthIdentifier())->delete();
        AuthMail::send($request->user()->email, 'Two-factor was turned off for your '.AuthMail::app().' account', ['Two-factor sign-in was just turned off for your account.', "If that wasn't you, change your password now."]);

        return response()->json(['enabled' => false]);
    }

    /** POST auth/two-factor/backup-codes: new codes; the old ones stop working. */
    public function regenerateBackupCodes(Request $request): JsonResponse
    {
        if (! $this->passwordConfirmed($request)) {
            return $this->refuse('INVALID_PASSWORD', "That isn't your password.", 422);
        }
        $second = Account::twoFactor($request->user());
        if ($second?->enabled_at === null) {
            return $this->refuse('TWO_FACTOR_NOT_ENABLED', 'Two-factor is not turned on.', 409);
        }
        [$codes, $hashes] = self::backupCodes();
        $second->fill(['backup_codes' => $hashes])->save();

        return response()->json(['backupCodes' => $codes]);
    }

    /**
     * Ten codes to keep somewhere safe, each good for one sign-in when the phone isn't
     * to hand. Shown once; only their hashes are kept.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function backupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODES; $i++) {
            $codes[] = strtolower(Str::random(5).'-'.Str::random(5));
        }

        return [$codes, array_map(Challenges::hash(...), $codes)];
    }

    /** GET auth/passkeys */
    public function passkeys(Request $request): JsonResponse
    {
        $this->enabled('passkeys');

        return response()->json([
            'data' => Passkey::query()->where('user_id', (string) $request->user()->getAuthIdentifier())->orderBy('created_at')->get()->map(self::describe(...))->all(),
        ]);
    }

    /** POST auth/passkeys/options: start adding a passkey. */
    public function passkeyOptions(Request $request): JsonResponse
    {
        $this->enabled('passkeys');

        return response()->json(Passkeys::creationOptions($request->user(), $request));
    }

    /** POST auth/passkeys: finish adding it. */
    public function addPasskey(Request $request): JsonResponse
    {
        $this->enabled('passkeys');
        $input = $request->validate(['challenge' => ['required', 'string'], 'response' => ['required', 'array'], 'name' => ['nullable', 'string', 'max:80']]);
        try {
            $passkey = Passkeys::create($request->user(), $input['challenge'], $input['response'], $input['name'] ?? null, $request);
        } catch (RuntimeException $e) {
            return $this->refuse('PASSKEY_FAILED', $e->getMessage(), 422);
        }

        return response()->json(self::describe($passkey), 201);
    }

    /** PATCH auth/passkeys/{id}: rename it. */
    public function renamePasskey(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $passkey = Passkey::query()->where('user_id', (string) $request->user()->getAuthIdentifier())->findOrFail($id);
        $passkey->fill(['name' => trim($input['name'])])->save();

        return response()->json(self::describe($passkey));
    }

    /** DELETE auth/passkeys/{id} */
    public function removePasskey(Request $request, string $id): Response
    {
        Passkey::query()->where('user_id', (string) $request->user()->getAuthIdentifier())->where('id', $id)->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private static function describe(Passkey $passkey): array
    {
        return [
            'id' => $passkey->id,
            'name' => $passkey->name,
            'createdAt' => $passkey->created_at?->toJSON(),
            'lastUsedAt' => $passkey->last_used_at?->toJSON(),
        ];
    }
}
