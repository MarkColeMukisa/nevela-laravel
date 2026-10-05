<?php

namespace Nevela\Laravel\Auth;

use Illuminate\Http\Request;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use Nevela\Laravel\Models\Passkey;
use RuntimeException;
use Throwable;

/**
 * Passkeys (WebAuthn): creating one, and signing in with one.
 *
 * Each is a ceremony in two halves. Laravel hands out a random challenge; the person's
 * device signs it with a key that never leaves the device; Laravel checks the signature
 * against the public key it was given when the passkey was made. The parsing and the
 * cryptography are lbuchs/webauthn's. What is checked here on top of it:
 *
 * - the challenge is one this app issued, minutes ago, and is used once,
 * - the page that ran the ceremony is the dashboard, compared exactly (the library
 *   accepts any host that merely ends with the right name), and
 * - the passkey belongs to the person it is being used for.
 */
final class Passkeys
{
    /** How long someone has to complete the prompt on their device. */
    private const SECONDS = 300;

    private static function server(Request $request): WebAuthn
    {
        // "none": no proof of the device's make is asked for. It isn't needed to sign
        // someone in, and asking makes browsers show an extra consent prompt.
        return new WebAuthn(AuthMail::app(), Web::host($request), ['none'], true);
    }

    /**
     * Options for `navigator.credentials.create()`.
     *
     * @return array{challenge: string, options: mixed}
     */
    public static function creationOptions(mixed $user, Request $request): array
    {
        $server = self::server($request);
        $existing = Passkey::query()->where('user_id', (string) $user->getAuthIdentifier())->pluck('credential_id')
            ->map(fn (string $id) => new ByteBuffer(self::decode($id)))->all();

        // A discoverable credential ("resident key"), so signing in doesn't need an email first.
        $options = $server->getCreateArgs(
            (string) $user->getAuthIdentifier(),
            (string) $user->email,
            (string) ($user->name ?: $user->email),
            self::SECONDS,
            true,
            'preferred',
            null,
            $existing,
        );

        return [
            'challenge' => Challenges::start('passkey-create', [
                'user' => (string) $user->getAuthIdentifier(),
                'value' => self::encode($server->getChallenge()->getBinaryString()),
                'origin' => Web::origin($request),
            ], self::SECONDS),
            'options' => $options->publicKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $response  What the browser's credential produced
     *
     * @throws RuntimeException with a message for the person
     */
    public static function create(mixed $user, string $challengeId, array $response, ?string $name, Request $request): Passkey
    {
        $challenge = Challenges::take('passkey-create', $challengeId);
        if ($challenge === null || $challenge['user'] !== (string) $user->getAuthIdentifier()) {
            throw new RuntimeException('That took too long. Try adding the passkey again.');
        }
        $clientData = self::decode((string) ($response['clientDataJSON'] ?? ''));
        self::assertOrigin($clientData, $challenge['origin']);

        try {
            $data = self::server($request)->processCreate(
                $clientData,
                self::decode((string) ($response['attestationObject'] ?? '')),
                self::decode($challenge['value']),
                false,
                true,
                false,
            );
        } catch (Throwable $e) {
            report($e);
            throw new RuntimeException("That passkey couldn't be checked. Try again.");
        }

        $credentialId = self::encode($data->credentialId);
        if (Passkey::query()->where('credential_id', $credentialId)->exists()) {
            throw new RuntimeException('That passkey is already added.');
        }
        $transports = array_values(array_filter((array) ($response['transports'] ?? []), fn ($t) => is_string($t) && preg_match('/^[a-z-]{2,20}$/', $t)));

        return Passkey::create([
            'user_id' => (string) $user->getAuthIdentifier(),
            'name' => $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 80) : null,
            'credential_id' => $credentialId,
            'public_key' => $data->credentialPublicKey,
            'counter' => (int) ($data->signatureCounter ?? 0),
            'transports' => $transports ?: null,
            'aaguid' => is_string($data->AAGUID ?? null) ? bin2hex($data->AAGUID) : null,
        ]);
    }

    /**
     * Options for `navigator.credentials.get()`. No list of allowed passkeys: the device
     * offers the ones it has for this site, which is what lets someone sign in without
     * typing an email, and tells a visitor nothing about who has an account.
     *
     * @return array{challenge: string, options: mixed}
     */
    public static function requestOptions(Request $request): array
    {
        $server = self::server($request);
        $options = $server->getGetArgs([], self::SECONDS, true, true, true, true, true, 'preferred');

        return [
            'challenge' => Challenges::start('passkey-get', [
                'value' => self::encode($server->getChallenge()->getBinaryString()),
                'origin' => Web::origin($request),
            ], self::SECONDS),
            'options' => $options->publicKey,
        ];
    }

    /**
     * Check a sign-in and return whose passkey it was.
     *
     * @param  array<string, mixed>  $response
     *
     * @throws RuntimeException with a message for the person
     */
    public static function verify(string $challengeId, array $response, Request $request): Passkey
    {
        $failed = "That passkey didn't work. Try again, or sign in another way.";
        $challenge = Challenges::take('passkey-get', $challengeId);
        if ($challenge === null) {
            throw new RuntimeException('That took too long. Try the passkey again.');
        }
        $passkey = Passkey::query()->where('credential_id', (string) ($response['id'] ?? ''))->first();
        if ($passkey === null) {
            throw new RuntimeException("This passkey isn't registered here any more. Sign in another way.");
        }
        $clientData = self::decode((string) ($response['clientDataJSON'] ?? ''));
        self::assertOrigin($clientData, $challenge['origin']);

        // The device says whose passkey it is. It has to agree with our record.
        $handle = (string) ($response['userHandle'] ?? '');
        if ($handle !== '' && ! hash_equals($passkey->user_id, self::decode($handle))) {
            throw new RuntimeException($failed);
        }

        $server = self::server($request);
        try {
            $server->processGet(
                $clientData,
                self::decode((string) ($response['authenticatorData'] ?? '')),
                self::decode((string) ($response['signature'] ?? '')),
                $passkey->public_key,
                self::decode($challenge['value']),
                // A counter that goes backwards means the key was copied. Passkeys synced
                // between someone's devices always report 0, which is not checked.
                $passkey->counter > 0 ? $passkey->counter : null,
                false,
                true,
            );
        } catch (Throwable $e) {
            report($e);
            throw new RuntimeException($failed);
        }

        $passkey->forceFill(['counter' => (int) ($server->getSignatureCounter() ?? $passkey->counter), 'last_used_at' => now()])->save();

        return $passkey;
    }

    /** The page that ran the ceremony has to be the dashboard, exactly. */
    private static function assertOrigin(string $clientDataJson, string $expected): void
    {
        $clientData = json_decode($clientDataJson, true);
        $origin = is_array($clientData) ? (string) ($clientData['origin'] ?? '') : '';
        if ($origin === '' || rtrim(strtolower($origin), '/') !== $expected) {
            throw new RuntimeException("This passkey request didn't come from the app's own address.");
        }
    }

    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), false);
    }
}
