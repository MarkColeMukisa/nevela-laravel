<?php

namespace Nevela\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Sanctum personal access tokens for the Next.js app.
 *
 * The Next.js server exchanges credentials for a token, keeps it in an httpOnly cookie,
 * and sends it as `Authorization: Bearer …` on every call to Laravel. The browser never
 * sees the token.
 */
final class TokenController
{
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'deviceName' => ['nullable', 'string', 'max:255'],
        ]);

        $model = config('auth.providers.users.model');
        $user = $model::query()->where('email', $credentials['email'])->first();
        if (! $user || ! Hash::check($credentials['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if (! method_exists($user, 'createToken')) {
            throw new RuntimeException('Add Laravel\Sanctum\HasApiTokens to '.$model.' to issue Nevela tokens.');
        }

        $token = $user->createToken($credentials['deviceName'] ?? config('nevela.auth.token_name', 'nevela-web'));

        return response()->json(['token' => $token->plainTextToken, 'user' => self::user($user)], 201);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => self::user($request->user())]);
    }

    public function destroy(Request $request): Response
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->noContent();
    }

    /** What the dashboard needs to know about the signed-in user. */
    public static function user(mixed $user): array
    {
        return [
            'id' => (string) $user->getAuthIdentifier(),
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
            'role' => $user->role ?? null,
        ];
    }
}
