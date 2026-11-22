<?php

namespace Nevela\Laravel\Http\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** What the auth controllers share: how they refuse, and how they check a setting or a password. */
trait Answers
{
    /** @param array<string, mixed> $more Anything else the client needs with it, such as where to go next */
    private function refuse(string $code, string $message, int $status = 400, array $more = []): JsonResponse
    {
        return response()->json(['error' => $message, 'code' => $code] + $more, $status);
    }

    /** A method switched off in config/nevela.php is refused, not just hidden. */
    private function enabled(string $setting): void
    {
        // A sign-in link opens a dashboard page: with no dashboard there is none to open.
        if (! config("nevela.auth.{$setting}", true) || ($setting === 'magic_link' && ! \Nevela\Laravel\Auth\Web::exists())) {
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
