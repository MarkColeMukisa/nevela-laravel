<?php

namespace Nevela\Laravel\Forms;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * A public form that was turned away, and what its sender is told.
 *
 * Which of three it is depends on who could be on the other end. A filled honeypot or a
 * forged token is a program, and is told it worked: it learns nothing to adjust to. A
 * failed robot check can happen to a person, so they are told, in a sentence. A problem
 * with the address they typed is a validation message on that field, like any other.
 */
final class Refusal
{
    /** Refusals nobody is told about. The answer is the one a success gets, and nothing is stored. */
    public const SILENT = ['honeypot', 'forged', 'too_fast'];

    public function __construct(
        public readonly string $reason,
        public readonly int $status = 422,
        public readonly string $message = '',
        public readonly ?string $field = null,
    ) {}

    public function silent(): bool
    {
        return in_array($this->reason, self::SILENT, true);
    }

    public function response(): JsonResponse|Response
    {
        if ($this->silent()) {
            $response = response()->noContent(201);
            // Whoever is building the form sees why nothing arrived. A bot in production doesn't.
            if (config('app.debug')) {
                $response->header('X-Nevela-Blocked', $this->reason);
            }

            return $response;
        }
        if ($this->field !== null) {
            return response()->json(['error' => 'Validation failed.', 'issues' => [['path' => $this->field, 'message' => $this->message]]], $this->status);
        }

        return response()->json(['error' => $this->message, 'code' => strtoupper($this->reason)], $this->status);
    }
}
