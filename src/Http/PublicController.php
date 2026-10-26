<?php

namespace Nevela\Laravel\Http;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Nevela\Laravel\Forms\Confirmation;
use Nevela\Laravel\Forms\FormBlocks;
use Nevela\Laravel\Forms\FormGuard;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Models\Upload;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Notices\Announce;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\PublicAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What anyone can call, signed in or not: a resource's "public" section.
 *
 *   GET  {prefix}/public/{slug}          the list, of the records the model says are public
 *   GET  {prefix}/public/{slug}/{id}     one of them, by id or by the field the section names
 *   POST {prefix}/public/{slug}          create one, from the fields the section lists
 *   PUT  {prefix}/public/_nevela/uploads/{Resource}/{field}   a file for such a create
 *   PUT  {prefix}/public/{slug}/_uploads/{field}              the same, by the form's own address
 *   GET  {prefix}/public/_nevela/form/{slug}                  what a protected form sends back with it
 *   POST {prefix}/public/_nevela/confirm/{slug}/{id}/{key}    the link that proves an address
 *
 * The routes are generated (routes/nevela-public.php), one set per resource that has a
 * section and only for what it opens, so a resource without one has nothing here to call.
 *
 * Nobody is asked who they are, so nothing here trusts what it is sent beyond what the
 * section allows: fields that aren't listed are never sent out and never taken in, and
 * the rules are the generated request's own.
 */
final class PublicController
{
    /** How long a file uploaded for a create waits for that create, in seconds. */
    public const CLAIM_SECONDS = 3600;

    public function index(Request $request): JsonResponse
    {
        [$descriptor, $public] = $this->open($request, 'read');
        $resourceClass = $this->resourceClass($descriptor);

        // Sorting, filtering and search are the signed-in list's, over the listed fields only.
        $response = Nevela::listFor($this->readable($descriptor), $descriptor->only($public->read), $request, $resourceClass);
        if ($response->getStatusCode() !== 200) {
            return $response;
        }
        $body = $response->getData(true);
        $body['data'] = array_map(fn (array $row) => $this->visible($descriptor, $public, $row), $body['data']);

        return response()->json($body);
    }

    public function show(Request $request): JsonResponse
    {
        [$descriptor, $public] = $this->open($request, 'read');
        $id = (string) $request->route('id');
        $query = $this->readable($descriptor);
        if ($public->by !== null) {
            $query->where($descriptor->fields[$public->by]->column(), $id);
        } elseif ($descriptor->idType === 'int' ? ! preg_match('/^[0-9]{1,18}$/', $id) : ! \Illuminate\Support\Str::isUuid($id)) {
            throw new NotFoundHttpException;
        } else {
            $query->whereKey($id);
        }
        $record = $query->first() ?? throw new NotFoundHttpException;
        $resourceClass = $this->resourceClass($descriptor);

        return response()->json($this->visible($descriptor, $public, (new $resourceClass($record))->resolve($request)));
    }

    public function store(Request $request): JsonResponse|Response
    {
        [$descriptor, $public] = $this->open($request, 'create');
        $requestClass = "App\\Http\\Requests\\Nevela\\{$descriptor->name}Request";
        $model = $this->model($descriptor);
        $input = $request->json()->all() ?: $request->request->all();
        $input = is_array($input) ? $input : [];

        // Before anything is validated or stored: what "protect" asks for. What is turned
        // away is counted, and a program is told it worked.
        if (($refusal = FormGuard::check($descriptor, $request, $input)) !== null) {
            FormBlocks::record($descriptor->name, $refusal->reason);

            return $refusal->response();
        }
        $input = FormGuard::strip($descriptor, $input);

        $address = null;
        if ($public->confirm !== null) {
            $column = $descriptor->fields[$public->confirm]->column();
            $typed = $input[$public->confirm] ?? null;
            if (is_string($typed) && filter_var(trim($typed), FILTER_VALIDATE_EMAIL)) {
                // One spelling of an address, so the same one can't join twice.
                $address = $input[$public->confirm] = strtolower(trim($typed));
                // Already on the list, or once was: answered exactly as the first time, so the
                // form can't be used to ask who is on it. Someone still waiting gets the link
                // again, as often as an address may be written to.
                $existing = DB::table($descriptor->table)->whereRaw("lower({$column}) = ?", [$address])->first();
                if ($existing !== null) {
                    if (property_exists($existing, 'confirmed_at') && $existing->confirmed_at === null && ($existing->deleted_at ?? null) === null) {
                        Confirmation::send($descriptor, $existing->id, $address, $request);
                    }

                    return response()->noContent(201);
                }
            }
        }

        $checked = (new $requestClass)->forFields($public->create, $input);
        // A file has to be one a visitor uploaded for this, a moment ago, and not yet used.
        foreach ($public->create as $field) {
            $key = $checked['values'][$descriptor->fields[$field]->column()] ?? null;
            if ($descriptor->fields[$field]->kind === 'file' && is_string($key) && $key !== '' && ! $this->claimable($descriptor, $field, $key)) {
                $checked['issues'][] = ['path' => $field, 'message' => 'Upload the file again: this one has been used, or was uploaded too long ago.'];
            }
        }
        if ($checked['issues'] !== []) {
            return response()->json(['error' => 'Validation failed.', 'issues' => $checked['issues']], 422);
        }

        $record = DB::transaction(function () use ($model, $checked, $descriptor, $public) {
            $record = new $model($checked['values']);
            if ($public->confirm !== null) {
                // Not yet: see Concerns\Confirmable.
                $record->setAttribute('confirmed_at', null);
            }
            $record->save();
            // Claimed: the nightly clear-out of uploads nobody used leaves it alone from here on.
            foreach ($public->create as $field) {
                $key = $descriptor->fields[$field]->kind === 'file' ? $record->getAttribute($descriptor->fields[$field]->column()) : null;
                if (is_string($key) && $key !== '') {
                    Upload::query()->where('key', $key)->update(['uploaded_by' => Uploads::CLAIMED]);
                }
            }

            return $record;
        });

        if ($address !== null) {
            Confirmation::send($descriptor, $record->getKey(), $address, $request);
        }
        // An address still to be proved is nobody yet: those are told of once it is.
        if ($public->confirm === null) {
            Announce::formReceived($descriptor, $record);
        }

        // What they may read of what they made, or nothing where nothing may be read. A
        // form with checks, or an address to prove, says nothing either way: the answer to
        // one that was sent has to be the answer to one that was quietly turned away.
        if ($public->read === null || $public->protected() || $public->confirm !== null) {
            return response()->noContent(201);
        }
        $resourceClass = $this->resourceClass($descriptor);

        return response()->json($this->visible($descriptor, $public, (new $resourceClass($record->refresh()))->resolve($request)), 201);
    }

    /**
     * What a protected form needs before it is shown: a token that says when it was handed
     * out, the name of the field a person never sees, and whether the robot check is on.
     */
    public function form(Request $request): JsonResponse
    {
        [$descriptor] = $this->open($request, 'create');

        return response()->json(FormGuard::issue($descriptor))->header('Cache-Control', 'no-store');
    }

    /**
     * The link in the email: the record it names is confirmed. The key is what only this
     * app could have written for that record and that address, so a link can't be made up
     * and stops working if the address is changed. Following it twice does no harm.
     */
    public function confirm(Request $request): JsonResponse
    {
        [$descriptor, $public] = $this->open($request, 'create');
        $id = (string) $request->route('id');
        $model = $this->model($descriptor);
        $gone = fn () => response()->json(['error' => 'This link is no longer good. It may have been used too late: sign up again for a new one.'], 404);
        if ($public->confirm === null || ($descriptor->idType === 'int' ? ! preg_match('/^[0-9]{1,18}$/', $id) : ! \Illuminate\Support\Str::isUuid($id))) {
            return $gone();
        }
        $record = $model::query()->whereKey($id)->first();
        $address = $record?->getAttribute($descriptor->fields[$public->confirm]->column());
        if ($record === null || ! is_string($address) || ! hash_equals(Confirmation::hash($descriptor, $id, $address), (string) $request->route('key'))) {
            return $gone();
        }
        if ($record->getAttribute('confirmed_at') === null) {
            $record->forceFill(['confirmed_at' => now()])->save();
            Announce::formConfirmed($descriptor, $record);
        }

        return response()->json(['confirmed' => true]);
    }

    /** A file for a public create: the field's own types and size limit, and no sign-in. */
    public function upload(Request $request): JsonResponse
    {
        [$descriptor, $public] = $this->open($request, 'create');
        $field = (string) $request->route('field');
        $definition = $descriptor->fields[$field] ?? null;
        if ($definition === null || $definition->kind !== 'file' || ! in_array($field, $public->create, true)) {
            throw new NotFoundHttpException;
        }
        $upload = UploadController::receive($request, $descriptor, $definition);
        if ($upload instanceof JsonResponse) {
            return $upload;
        }
        // Marked as a visitor's, waiting to be used: see claimable() and Uploads::purgeUnclaimed().
        $upload->update(['uploaded_by' => Uploads::UNCLAIMED]);

        return response()->json(Uploads::ref($upload->key), 201);
    }

    /**
     * The resource a route is for, if this part of it is open. The route is only there when
     * it is, so this failing means a route file older than the descriptor.
     *
     * Which resource is a default set on the route. Laravel hands route parameters to a
     * controller in order and not by name, with defaults after what the address holds, so
     * it is asked for by its name here.
     *
     * @return array{Descriptor, PublicAccess}
     */
    private function open(Request $request, string $what): array
    {
        try {
            $descriptor = Nevela::resource((string) $request->route('resource'));
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException;
        }
        if ($descriptor->public?->{$what} === null || ! class_exists($this->model($descriptor))) {
            throw new NotFoundHttpException;
        }

        return [$descriptor, $descriptor->public];
    }

    /** @return class-string<Model> */
    private function model(Descriptor $descriptor): string
    {
        return "App\\Models\\{$descriptor->name}";
    }

    /** @return class-string<\Illuminate\Http\Resources\Json\JsonResource> */
    private function resourceClass(Descriptor $descriptor): string
    {
        return "App\\Http\\Resources\\Nevela\\{$descriptor->name}Resource";
    }

    /**
     * The records anyone may read: what the model's publicQuery scope lets through. A model
     * without the scope has said nothing, and nothing is what is public.
     */
    private function readable(Descriptor $descriptor): Builder
    {
        $model = $this->model($descriptor);
        $query = $model::query();

        return method_exists($model, 'scopePublicQuery') ? $query->publicQuery() : $query->whereRaw('1 = 0');
    }

    /**
     * A record as anyone may see it: its id and the listed fields, and for a file the
     * details that go with its key. Nothing else, whatever the API resource holds.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function visible(Descriptor $descriptor, PublicAccess $public, array $row): array
    {
        $seen = ['id' => $row['id'] ?? null];
        foreach ($public->read ?? [] as $field) {
            $seen[$field] = $row[$field] ?? null;
            if ($descriptor->fields[$field]->kind === 'file') {
                $seen[$field.'File'] = $row[$field.'File'] ?? null;
            }
        }

        return $seen;
    }

    /** Whether a key is a file a visitor uploaded to this field within the hour, that no record has yet. */
    private function claimable(Descriptor $descriptor, string $field, string $key): bool
    {
        $upload = Upload::query()->where('key', $key)->where('resource', $descriptor->name)->where('field', $field)->first();

        return $upload !== null
            && $upload->getAttribute('uploaded_by') === Uploads::UNCLAIMED
            && $upload->created_at !== null && $upload->created_at->gt(now()->subSeconds(self::CLAIM_SECONDS))
            && ! DB::table($descriptor->table)->where($descriptor->fields[$field]->column(), $key)->exists();
    }
}
