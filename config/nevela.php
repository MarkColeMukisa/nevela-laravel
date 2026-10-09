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

    /*
    | Permissions of your own, beside the ones every resource gets (products.view,
    | products.create, products.edit, products.delete) and the built-in users.* and roles.*.
    | They appear on the dashboard's Roles screen, and you check one anywhere in Laravel
    | with `$user->can('reports.view')`. Actions are any of: create, view, edit, delete.
    |
    |   'permissions' => [
    |       'reports' => ['name' => 'Reports', 'actions' => ['view']],
    |   ],
    */
    'permissions' => [],

    /*
    | Signing in. Every endpoint is under {prefix}/auth. Email and password is always
    | available; the rest can be switched off here. A method that is off is refused by the
    | API, not just hidden, and `php artisan nevela:generate` writes these choices to the
    | dashboard (apps/web/lib/auth-config.ts) so its screens agree.
    */
    'auth' => [
        'enabled' => true,
        'token_name' => 'nevela-web',

        /*
        | Let anyone create their own account at /sign-up. Off by default, and accounts come
        | from `php nevela user`: a generated policy lets every signed-in user do everything
        | until you tighten it, so open sign-up on an untouched app would hand the dashboard
        | to whoever finds it. Tighten app/Policies first, then set NEVELA_REGISTRATION=true.
        */
        'registration' => (bool) env('NEVELA_REGISTRATION', false),

        /* A sign-in link by email. */
        'magic_link' => true,

        /* A 6-digit sign-in code by email. */
        'email_code' => true,

        /* Face ID, Touch ID, Windows Hello or a security key. */
        'passkeys' => true,

        'two_factor' => [
            /* Codes from an authenticator app (TOTP), with backup codes. */
            'authenticator' => true,
            /* Codes by email as the second step. */
            'email' => true,
        ],

        /* Refuse password sign-in until the email address is verified. */
        'require_email_verification' => false,

        /*
        | Check new passwords against Have I Been Pwned's breach list. Only the first five
        | characters of the password's hash are sent, and an outage lets the password through.
        */
        'check_breached_passwords' => true,

        /*
        | Sign-in attempts are limited to ten a minute per account. This is the limit per
        | address on top of that. It is high because the dashboard's server makes the calls:
        | to Laravel, everyone using the dashboard comes from that one address.
        */
        'attempts_per_address' => 300,

        /*
        | Let people close their own account, from the dashboard's Account page. A closed
        | account is kept under Deleted accounts, for an administrator to restore or remove
        | for good. Off: only someone who may delete users can close one.
        */
        'close_account' => true,

        /*
        | The role a self-registered account starts with. USER allows nothing beyond the
        | person's own account, which is what makes open sign-up safe. Null gives no role.
        */
        'default_role' => 'USER',

        /*
        | Where the dashboard is, for the links in emails and for passkeys, which are tied
        | to its address. In development any localhost port is accepted as well, because
        | the dashboard moves to another port when 3000 is taken.
        */
        'web_url' => env('NEVELA_WEB_URL', 'http://localhost:3000'),

        /*
        | A secret the dashboard's server and Laravel share, so Laravel can believe what the
        | dashboard says about a person's browser and address (for the list of devices).
        | Put the same value in the dashboard's .env.local. New apps get one when created.
        */
        'proxy_secret' => env('NEVELA_PROXY_SECRET'),

        /* The name an authenticator app and a passkey prompt show. Null: the app's name. */
        'issuer' => null,
    ],

    /* List defaults, matching Flare's client. */
    'per_page' => 25,
    'max_per_page' => 100,

    /*
    | The trash. A deleted record is kept, hidden from every list and count, and can be
    | restored from the dashboard's Trash page for this many days. Then it is removed for
    | good: each night by `nevela:trash`, which needs Laravel's scheduler running, and
    | whenever someone opens the trash. Null keeps deleted records until someone removes them.
    */
    'trash' => [
        'days' => 30,
    ],

    /*
    | The most rows POST /{slug}/_bulk creates in one request (the dashboard's "Add several"
    | grid). They are saved in one transaction, so this is also how long one can run.
    */
    'bulk_max' => 500,

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
