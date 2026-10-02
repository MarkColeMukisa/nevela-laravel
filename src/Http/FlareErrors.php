<?php

namespace Nevela\Laravel\Http;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Naming;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Laravel exceptions as Flare's error bodies, for requests under Nevela's prefix:
 *
 *   422 { error, issues: [{ path, message }] }   validation
 *   409 { error, field }                         unique conflict that slipped past validation
 *   401 / 403 / 404 / 405 / 429 { error }
 *
 * Flare's client turns these into ApiError(status, issues, field), so its forms show
 * Laravel's messages next to the right inputs.
 */
final class FlareErrors
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! Nevela::handles($request)) {
            return null;
        }

        return match (true) {
            $e instanceof ValidationException => response()->json([
                'error' => 'Validation failed.',
                'issues' => self::issues($e->errors()),
            ], 422),
            $e instanceof AuthenticationException => response()->json(['error' => 'Sign in to continue.'], 401),
            $e instanceof UniqueConstraintViolationException => response()->json(array_filter([
                'error' => 'A record with that value already exists.',
                'field' => self::conflictField($e->getMessage()),
            ]), 409),
            $e instanceof HttpExceptionInterface => response()->json(
                ['error' => self::message($e)],
                $e->getStatusCode(),
                $e->getHeaders(),
            ),
            default => null,
        };
    }

    /** @param array<string, list<string>> $errors @return list<array{path: string, message: string}> */
    public static function issues(array $errors): array
    {
        $issues = [];
        foreach ($errors as $path => $messages) {
            foreach ($messages as $message) {
                $issues[] = ['path' => (string) $path, 'message' => $message];
            }
        }

        return $issues;
    }

    /** Best effort: the column a unique index rejected, as a descriptor field name. */
    public static function conflictField(string $message): ?string
    {
        $patterns = [
            '/UNIQUE constraint failed: \w+\.(\w+)/',          // SQLite
            '/Key \((\w+)\)=/',                                 // Postgres
            "/for key '(?:\\w+\\.)?\\w+?_(\\w+)_unique'/",      // MySQL
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $m)) {
                return Naming::camel($m[1]);
            }
        }

        return null;
    }

    private static function message(HttpExceptionInterface $e): string
    {
        return match ($e->getStatusCode()) {
            403 => 'You don\'t have access to this.',
            404 => 'Not found.',
            405 => 'Method not allowed.',
            429 => 'Too many requests. Try again shortly.',
            default => $e->getMessage() ?: 'Request failed.',
        };
    }
}
