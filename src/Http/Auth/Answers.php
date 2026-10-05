<?php

namespace Nevela\Laravel\Http\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** What the auth controllers share: how they refuse, and how they check a setting or a password. */
trait Answers
{
    private function refuse(string $code, string $message, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $message, 'code' => $code], $status);
    }

    /** A method switched off in config/nevela.php is refused, not just hidden. */
    private function enabled(string $setting): void
    {
        if (! config("nevela.auth.{$setting}", true)) {
            throw new NotFoundHttpException("This sign-in method isn't enabled.");
        }
    }

    /** Changing how an account is protected takes its password again. */
    private function passwordConfirmed(Request $request): bool
    {
        $password = $request->input('password');

        return is_string($password) && Hash::check($password, (string) $request->user()->getAuthPassword());
    }
}
