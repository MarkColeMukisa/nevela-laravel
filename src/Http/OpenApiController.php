<?php

namespace Nevela\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\OpenApi;

/**
 * GET _nevela/openapi.json: the API as OpenAPI 3.1, from the descriptors. Signed in, as
 * GET _nevela/resources is: it says the same things. `php artisan nevela:openapi` writes
 * it to a file for a tool that can't send a token.
 */
final class OpenApiController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(self::document(), 200, [], JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    public static function document(): array
    {
        return (new OpenApi(
            Nevela::all(),
            // APP_URL, the address the app is reached at, and not whichever one this request came by:
            // the dashboard asks Laravel by an address of its own, which may be one only it can reach.
            rtrim((string) (config('app.url') ?: url('/')), '/').'/'.trim((string) config('nevela.prefix', 'api'), '/'),
            title: (string) config('app.name', 'API'),
            version: Nevela::VERSION,
            perPage: (int) config('nevela.per_page', 25),
            maxPerPage: (int) config('nevela.max_per_page', 100),
            bulkMax: max(1, (int) config('nevela.bulk_max', 500)),
            currency: is_string(config('nevela.currency')) ? config('nevela.currency') : null,
            auth: (bool) config('nevela.auth.enabled', true),
        ))->document();
    }
}
