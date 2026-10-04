<?php

return [
    /*
    | Where resource descriptors live. One JSON file per resource; they are the
    | generation input. Laravel code generated from them is the runtime authority.
    */
    'descriptors_path' => base_path('nevela/resources'),

    /*
    | The Flare-based Next.js app. Generated `.resource.ts` descriptors are written to
    | {web_path}/resources. Set to null to skip frontend output.
    */
    'web_path' => env('NEVELA_WEB_PATH', base_path('../web')),

    /*
    | The top of the project, where the `php nevela` launcher is written. Null works it
    | out: two folders up when this app is at <project>/apps/<name>. Set a path if yours
    | is somewhere else, or false to have no launcher.
    */
    'root_path' => env('NEVELA_ROOT_PATH'),

    /* URL prefix and middleware for generated resource routes (routes/nevela.php). */
    'prefix' => 'api',
    'middleware' => ['api', 'auth:sanctum'],

    /* Token auth endpoints for the Next.js app: POST/DELETE {prefix}/auth/token, GET {prefix}/auth/me. */
    'auth' => [
        'enabled' => true,
        'token_name' => 'nevela-web',
    ],

    /* List defaults, matching Flare's client. */
    'per_page' => 25,
    'max_per_page' => 100,

    /*
    | File and image fields (image:image, manual:file(pdf)).
    |
    | Files go on a disk from config/filesystems.php and are handed out by Nevela's own
    | route, GET {prefix}/_nevela/files/{key}, so nothing needs linking or publishing. Keys
    | are unguessable, but anyone who has one can fetch the file: don't use these fields
    | for documents that must stay private. If a CDN or your web server serves the disk,
    | put its address in NEVELA_UPLOADS_URL and the API's links point there.
    */
    'uploads' => [
        'disk' => env('NEVELA_UPLOADS_DISK', 'public'),
        'url' => env('NEVELA_UPLOADS_URL'),

        /* Where untouched originals are kept, so an image can be optimised again later. Never served. */
        'originals_disk' => env('NEVELA_ORIGINALS_DISK', 'local'),

        /* The largest file a field takes. */
        'max_bytes' => 10 * 1024 * 1024,

        /* Memory allowed while an image is being optimised: a phone photo needs more than PHP's usual 128M. */
        'memory' => '512M',

        /*
        | How images are optimised. Every image field uses "default" unless it names another:
        | image:image(product). A profile only says what differs from the default.
        |
        |   max         [width, height] the stored image fits inside. Add 'crop' to fill it exactly.
        |   quality     1 to 100.
        |   format      auto, webp, jpeg, png or avif. Auto keeps transparency and otherwise
        |               picks the smallest.
        |   renditions  extra sizes made alongside, by name. Ask for one by putting its name
        |               before the extension: kettle.webp → kettle.thumb.webp
        |   keep_original  keep the untouched upload on the originals disk.
        |   on_error    store_original (keep the upload as it came) or reject.
        |   max_pixels  refuse anything that would decode to more than this.
        */
        'profiles' => [
            'default' => [
                'max' => [1600, 1600],
                'quality' => 82,
                'format' => 'auto',
                'renditions' => ['thumb' => [400, 400, 'crop']],
                'keep_original' => true,
                'on_error' => 'store_original',
                'max_pixels' => 50_000_000,
            ],

            // A product photograph: smaller than the default, because a catalogue page shows a dozen at once.
            'product' => [
                'max' => [1000, 1000],
                'quality' => 80,
                'renditions' => ['thumb' => [300, 300, 'crop'], 'card' => [600, 600]],
            ],

            // Always square and always small, so it crops: a portrait in a round frame shouldn't letterbox.
            'avatar' => [
                'max' => [400, 400, 'crop'],
                'quality' => 85,
                'renditions' => ['thumb' => [80, 80, 'crop']],
                'keep_original' => false,
            ],

            // A hero or cover image, where width matters more than total pixels.
            'cover' => [
                'max' => [2400, 1200],
                'renditions' => ['thumb' => [600, 300, 'crop']],
            ],
        ],
    ],
];
