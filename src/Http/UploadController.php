<?php

namespace Nevela\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Nevela\Laravel\Media\FileTypes;
use Nevela\Laravel\Media\UploadRejected;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Models\Upload;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
use Nevela\Laravel\Support\Key;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receiving files and handing them back.
 *
 *   PUT {prefix}/_nevela/uploads/{Resource}/{field}?name=kettle.jpg   the file is the request body
 *   GET {prefix}/_nevela/files/{key}                                  the stored file
 *
 * The body is the file itself, not a form: that is what a browser's upload progress
 * works with, and it isn't held to PHP's 2 MB default for form uploads.
 */
final class UploadController
{
    public function store(Request $request, string $resource, string $field): JsonResponse
    {
        try {
            $descriptor = Nevela::resource($resource);
        } catch (InvalidArgumentException) {
            return self::refuse(404, "There is no resource called {$resource}.");
        }
        $definition = $descriptor->fields[$field] ?? null;
        if ($definition === null || $definition->kind !== 'file') {
            return self::refuse(404, "{$resource} has no file field called {$field}.");
        }
        $model = 'App\\Models\\'.$descriptor->name;
        // Storing a file is a write, so it takes the permission to create a record. Being
        // allowed to look at the resource is not enough.
        if (class_exists($model) && Gate::getPolicyFor($model) !== null && ! Gate::allows('create', $model)) {
            return self::refuse(403, "You can't upload files to {$descriptor->pluralLabel}.");
        }

        $upload = self::receive($request, $descriptor, $definition);

        return $upload instanceof JsonResponse ? $upload : response()->json(Uploads::ref($upload->key), 201);
    }

    /**
     * Take the file in a request's body, check it against a field and store it. Returns
     * the upload, or the response that says why it was refused. Shared with the profile
     * picture, which is an image field on the user without being a generated resource.
     */
    public static function receive(Request $request, Descriptor $descriptor, Field $definition): Upload|JsonResponse
    {
        $limit = Uploads::maxBytes();
        $megabytes = round($limit / 1024 / 1024, 1);

        // The file is the request's body, or a form's "file" part. A phone sends the form:
        // React Native reads the picture from disk itself, where a body made in JavaScript
        // arrives altered on Android. PHP reads forms only from a POST, so the app sends
        // one with _method=PUT, and Laravel routes it as the PUT it stands for.
        $part = $request->file('file');
        if ($part instanceof UploadedFile) {
            $name = basename(str_replace('\\', '/', (string) $request->query('name', $part->getClientOriginalName() ?: 'file')));
            if (! $part->isValid()) {
                return in_array($part->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? self::refuse(413, "{$name} is larger than this server takes from a form (upload_max_filesize is ".ini_get('upload_max_filesize').').')
                    : self::refuse(422, "{$name} didn't arrive whole. Try again.");
            }
            if ((int) $part->getSize() > $limit) {
                return self::refuse(413, "{$name} is larger than the {$megabytes} MB this field takes.");
            }
            $declared = $part->getClientMimeType() ?: 'application/octet-stream';
            $in = fopen($part->getRealPath(), 'rb');
        } else {
            $name = basename(str_replace('\\', '/', (string) $request->query('name', 'file')));
            if ((int) $request->header('Content-Length', '0') > $limit) {
                return self::refuse(413, "{$name} is larger than the {$megabytes} MB this field takes.");
            }
            $declared = (string) $request->header('Content-Type', 'application/octet-stream');
            $in = $request->getContent(true);
        }

        // Copied to a temporary file with the limit enforced while it arrives: the length
        // a client declares is not something to rely on.
        $path = Uploads::scratchFile();

        try {
            $out = fopen($path, 'wb');
            try {
                $written = stream_copy_to_stream($in, $out, $limit + 1);
            } finally {
                fclose($out);
            }
            if ($written === false || $written === 0) {
                return self::refuse(422, 'The upload arrived empty. Try again.');
            }
            if ($written > $limit) {
                return self::refuse(413, "{$name} is larger than the {$megabytes} MB this field takes.");
            }

            $mime = FileTypes::sniff($path);
            // The contents decide. What was declared only has to agree with them.
            $effective = FileTypes::accepts($definition->accept, $mime) ? $mime : $declared;
            if (FileTypes::forbidden($mime) || ! FileTypes::accepts($definition->accept, $effective) || ! FileTypes::consistent($declared, $mime)) {
                $wanted = in_array('any', $definition->accept, true) ? 'a file this field can store' : implode(' or ', $definition->accept).' data';

                return self::refuse(422, "{$name} doesn't contain {$wanted}. It may have been renamed; choose the original file.");
            }

            try {
                return Uploads::store($descriptor, $definition, $name, $path, strtolower(trim(explode(';', $effective)[0])), (string) ($request->user()?->getAuthIdentifier() ?? '') ?: null);
            } catch (UploadRejected $e) {
                return self::refuse(422, $e->getMessage());
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * A stored file. Keys are unguessable and never reused, so the response can be cached
     * for good. Asking for a rendition that was never made (the image wasn't one that could
     * be optimised) gets the file itself, so a client can always ask for a thumbnail.
     */
    public function show(string $path): Response
    {
        // A key is letters, digits, dashes, dots and slashes. Anything else was never one.
        // And a private file's key is not an address, whoever has it.
        if ($path === '' || str_contains($path, '..') || ! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $path) || Uploads::isPrivate($path)) {
            abort(404);
        }
        $cache ='public, max-age=31536000, immutable';

        // Only what was uploaded through Nevela is served, found by its record. The disk may
        // hold other things, and being on the disk is not a reason to hand a file out.
        $upload = Upload::query()->where('key', $path)->first();
        if ($upload === null) {
            // Not a stored image: a rendition, then. "kettle.thumb.webp" belongs to the upload
            // whose key is "kettle" and one extension.
            $base = preg_replace('/\.[a-z][a-z0-9-]*\.[A-Za-z0-9]+$/', '', $path);
            if ($base === null || $base === $path) {
                abort(404);
            }
            // LIKE narrows it down; the exact comparison after it is what decides. ("_" in a
            // key is a wildcard to LIKE, and how to escape it differs between databases.)
            $upload = Upload::query()->where('key', 'like', $base.'.%')->limit(20)->get()
                ->first(fn (Upload $candidate) => str_starts_with($candidate->key, $base.'.')
                    && preg_match('/^\.[A-Za-z0-9]+$/', substr($candidate->key, strlen($base))) === 1);
            if ($upload === null) {
                abort(404);
            }
            if (! in_array($path, array_column($upload->renditions ?? [], 'key'), true)) {
                // A rendition that was never made: the image itself, so a client can always
                // ask for a thumbnail. Cached briefly, in case one is made later.
                $path = $upload->key;
                $cache = 'public, max-age=300';
            }
        }

        $disk = Storage::disk($upload->disk);
        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, null, [
            'Cache-Control' => $cache,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A private file, to someone who may view the record it belongs to.
     *
     *   GET {prefix}/_nevela/private/{slug}/{id}/{field}
     *
     * The address names the record and the field, and the file is found from them: there
     * is no key in it to pass around. It is never to be kept by a cache on the way.
     */
    public function download(string $slug, string $id, string $field): Response
    {
        [$record, $definition] = $this->privateRecord($slug, $id, $field);
        Gate::authorize('view', $record);

        return $this->privateFile($record, $definition);
    }

    /** How long a private file's link works: long enough to open it, too short to pass around. */
    public const LINK_SECONDS = 300;

    /**
     * An address for a private file that works for a few minutes without signing in.
     *
     *   POST {prefix}/_nevela/private/{slug}/{id}/{field}/link   → { path, expiresAt }
     *
     * For a phone, which opens the file in its browser, and a browser can't send the app's
     * token. Given only to someone who may view the record. The link names the file it was
     * made for: once the record is trashed or the file replaced, it opens nothing.
     */
    public function link(string $slug, string $id, string $field): JsonResponse
    {
        [$record, $definition] = $this->privateRecord($slug, $id, $field);
        Gate::authorize('view', $record);
        $this->privateFile($record, $definition);
        $expires = now()->addSeconds(self::LINK_SECONDS);
        $file = self::fingerprint((string) $record->getAttribute($definition->column()));

        return response()->json([
            // The path alone is signed, as the API's own address can read differently behind a proxy.
            // The phone puts it after the address it reaches the API by.
            'path' => URL::temporarySignedRoute('nevela.private.signed', $expires, ['slug' => $slug, 'id' => $id, 'field' => $field, 'file' => $file], absolute: false),
            'expiresAt' => $expires->toAtomString(),
        ]);
    }

    /** GET {prefix}/_nevela/private-link/{slug}/{id}/{field}?file=…&expires=…&signature=…: the file behind a link. */
    public function signed(Request $request, string $slug, string $id, string $field): Response
    {
        [$record, $definition] = $this->privateRecord($slug, $id, $field);
        if (! hash_equals(self::fingerprint((string) $record->getAttribute($definition->column())), (string) $request->query('file'))) {
            abort(404);
        }

        return $this->privateFile($record, $definition);
    }

    /** Which file a link was made for, without putting its key in the address. */
    private static function fingerprint(string $key): string
    {
        return substr(hash_hmac('sha256', $key, (string) config('app.key')), 0, 32);
    }

    /**
     * The record a private file belongs to, and the field. 404 for anything else.
     *
     * @return array{0: \Illuminate\Database\Eloquent\Model, 1: Field}
     */
    private function privateRecord(string $slug, string $id, string $field): array
    {
        $descriptor = null;
        foreach (Nevela::all() as $candidate) {
            if ($candidate->slug === $slug) {
                $descriptor = $candidate;
            }
        }
        $definition = $descriptor?->fields[$field] ?? null;
        $model = 'App\\Models\\'.($descriptor?->name ?? '');
        if ($descriptor === null || $definition === null || $definition->kind !== 'file' || ! $definition->private || ! class_exists($model) || ! Key::fits($model, $id)) {
            abort(404);
        }
        // A record in the trash is found by nobody here: trashing it exposes nothing.
        $record = $model::query()->find($id) ?? abort(404);

        return [$record, $definition];
    }

    private function privateFile(mixed $record, Field $definition): Response
    {
        $key = $record->getAttribute($definition->column());
        $upload = Uploads::isPrivate($key) ? Upload::query()->where('key', $key)->first() : null;
        $disk = $upload === null ? null : Storage::disk($upload->disk);
        if ($upload === null || $disk === null || ! $disk->exists(Uploads::privatePath($key))) {
            abort(404);
        }

        return $disk->response(Uploads::privatePath($key), $upload->name, [
            'Content-Type' => $upload->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'attachment');
    }

    private static function refuse(int $status, string $message): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
