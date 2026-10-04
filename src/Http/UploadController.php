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
use Nevela\Laravel\Nevela;
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
            return $this->refuse(404, "There is no resource called {$resource}.");
        }
        $definition = $descriptor->fields[$field] ?? null;
        if ($definition === null || $definition->kind !== 'file') {
            return $this->refuse(404, "{$resource} has no file field called {$field}.");
        }
        $model = 'App\\Models\\'.$descriptor->name;
        if (class_exists($model) && Gate::getPolicyFor($model) !== null && ! Gate::any(['create', 'viewAny'], $model)) {
            return $this->refuse(403, "You can't upload files to {$descriptor->pluralLabel}.");
        }

        $name = basename(str_replace('\\', '/', (string) $request->query('name', 'file')));
        $limit = Uploads::maxBytes();
        $megabytes = round($limit / 1024 / 1024, 1);
        if ((int) $request->header('Content-Length', '0') > $limit) {
            return $this->refuse(413, "{$name} is larger than the {$megabytes} MB this field takes.");
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
                return $this->refuse(422, 'The upload arrived empty. Try again.');
            }
            if ($written > $limit) {
                return $this->refuse(413, "{$name} is larger than the {$megabytes} MB this field takes.");
            }

            $mime = FileTypes::sniff($path);
            $declared = (string) $request->header('Content-Type', 'application/octet-stream');
            // The contents decide. What was declared only has to agree with them.
            $effective = FileTypes::accepts($definition->accept, $mime) ? $mime : $declared;
            if (FileTypes::forbidden($mime) || ! FileTypes::accepts($definition->accept, $effective) || ! FileTypes::consistent($declared, $mime)) {
                $wanted = in_array('any', $definition->accept, true) ? 'a file this field can store' : implode(' or ', $definition->accept).' data';

                return $this->refuse(422, "{$name} doesn't contain {$wanted}. It may have been renamed; choose the original file.");
            }

            try {
                $upload = Uploads::store($descriptor, $definition, $name, $path, strtolower(trim(explode(';', $effective)[0])), (string) ($request->user()?->getAuthIdentifier() ?? '') ?: null);
            } catch (UploadRejected $e) {
                return $this->refuse(422, $e->getMessage());
            }

            return response()->json(Uploads::ref($upload->key), 201);
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
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || str_starts_with($path, 'nevela/originals/')) {
            abort(404);
        }
        $disk = Storage::disk(Uploads::disk());
        $cache = 'public, max-age=31536000, immutable';

        if (! $disk->exists($path)) {
            $fallback = preg_replace('/\.[a-z][a-z0-9-]*(\.[A-Za-z0-9]+)$/', '$1', $path);
            if ($fallback === $path || $fallback === null) {
                abort(404);
            }
            $base = substr($fallback, 0, (int) strrpos($fallback, '.'));
            $match = null;
            foreach ($disk->files(dirname($fallback)) as $candidate) {
                // The stored file may have a different extension from the one asked for.
                if (str_starts_with($candidate, $base.'.') && substr_count(basename($candidate), '.') === 1) {
                    $match = $candidate;
                    break;
                }
            }
            if ($match === null) {
                abort(404);
            }
            $path = $match;
            $cache = 'public, max-age=300';
        }

        return $disk->response($path, null, [
            'Cache-Control' => $cache,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function refuse(int $status, string $message): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
