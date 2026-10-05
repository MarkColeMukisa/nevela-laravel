<?php

namespace Nevela\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Nevela\Laravel\Media\FileTypes;
use Nevela\Laravel\Media\UploadRejected;
use Nevela\Laravel\Media\Uploads;
use Nevela\Laravel\Models\Upload;
use Nevela\Laravel\Nevela;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\Field;
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
        $name = basename(str_replace('\\', '/', (string) $request->query('name', 'file')));
        $limit = Uploads::maxBytes();
        $megabytes = round($limit / 1024 / 1024, 1);
        if ((int) $request->header('Content-Length', '0') > $limit) {
            return self::refuse(413, "{$name} is larger than the {$megabytes} MB this field takes.");
        }

        // Copied to a temporary file with the limit enforced while it arrives: the length
        // a client declares is not something to rely on.
        $path = Uploads::scratchFile();

        try {
            $out = fopen($path, 'wb');
            try {
                $written = stream_copy_to_stream($request->getContent(true), $out, $limit + 1);
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
            $declared = (string) $request->header('Content-Type', 'application/octet-stream');
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
        if ($path === '' || str_contains($path, '..') || ! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $path)) {
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

    private static function refuse(int $status, string $message): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
