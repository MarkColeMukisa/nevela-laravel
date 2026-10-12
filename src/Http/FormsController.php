<?php

namespace Nevela\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Nevela\Laravel\Forms\FormBlocks;
use Nevela\Laravel\Forms\FormGuard;
use Nevela\Laravel\Nevela;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What a resource's public form has been doing, for whoever may see the resource:
 *
 *   GET {prefix}/_nevela/forms/{slug}   → { protect, confirm, robotCheck, blocked }
 *
 * `blocked` is what was turned away in the last week, in all and by reason. It is how a
 * form that is well protected is told from one nobody uses.
 */
final class FormsController
{
    public function show(string $slug): JsonResponse
    {
        $descriptor = null;
        foreach (Nevela::all() as $candidate) {
            if ($candidate->slug === $slug) {
                $descriptor = $candidate;
            }
        }
        $model = $descriptor === null ? null : "App\\Models\\{$descriptor->name}";
        if ($descriptor === null || $descriptor->public?->create === null || ! class_exists((string) $model)) {
            throw new NotFoundHttpException;
        }
        Gate::authorize('viewAny', $model);
        $protect = $descriptor->public->protect;

        return response()->json([
            'protect' => $protect,
            'confirm' => $descriptor->public->confirm,
            // Asked for and not possible: the form is refusing everyone (production) or checking nobody (development).
            'robotCheck' => ($protect['turnstile'] ?? false) ? FormGuard::robotCheck() : 'off',
            'blocked' => FormBlocks::summary($descriptor->name),
        ]);
    }
}
