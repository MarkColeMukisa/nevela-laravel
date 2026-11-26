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
    | The staff's own Next.js app, when the project has one (the triple tier: the public
    | site and customer area in web_path, the admin here). While it exists, the dashboard's
    | pages are generated here instead of in web_path, and the descriptors in both.
    | Set to null if the project has none.
    */
    'admin_path' => env('NEVELA_ADMIN_PATH', base_path('../admin')),

    /*
    | Whether the app has a dashboard: false for an API and a mobile app alone
    | (`nevela new --tier mobile`). Emails then carry codes and no links to dashboard
    | pages, and `deploy:check` doesn't ask where the dashboard is. A setting, not a
    | look for the folder, because a server has the API without the dashboard beside it.
    */
    'dashboard' => (bool) env('NEVELA_DASHBOARD', true),

    /*
    | The Expo mobile app, when the project has one (nevela new my-app --mobile). While
    | it exists, `nevela dev` opens the API to your network so a phone can reach it.
    | Set to null if you never want that.
    */
    'mobile_path' => env('NEVELA_MOBILE_PATH', base_path('../mobile')),

    /*
    | The top of the project, where the `php nevela` launcher is written. Null works it
    | out: two folders up when this app is at <project>/apps/<name>. Set a path if yours
    | is somewhere else, or false to have no launcher.
    */
    'root_path' => env('NEVELA_ROOT_PATH'),

    /*
    | Security in the app itself, on any host (DDoS is the edge's job: see the Laravel Cloud guide).
    | - api_per_person, api_per_address: requests a minute on the signed-in API, per person and per
    |   address. A request beyond answers 429 with when to try again. 0 switches one off.
    | - scanners: requests for /.env, wp-admin, phpMyAdmin and other probes answer 404 at once, and
    |   count against the address. scanner_paths: more, as regular expressions on the path.
    */
    'security' => [
        'api_per_person' => env('NEVELA_API_PER_PERSON', 600),
        'api_per_address' => env('NEVELA_API_PER_ADDRESS', 1200),
        'scanners' => (bool) env('NEVELA_SCANNERS', true),
        'scanner_paths' => [],
        // Repeat offenders: this many strikes (a scanner's probe, a request beyond the per-address
        // limit) within block_window minutes, and the address is blocked for block_minutes.
        // 0 strikes: never blocked by itself. An administrator blocks and clears by hand too.
        'block_after' => env('NEVELA_BLOCK_AFTER', 20),
        'block_window' => env('NEVELA_BLOCK_WINDOW', 10),
        'block_minutes' => env('NEVELA_BLOCK_MINUTES', 60),
        // Never blocked: addresses and ranges ("203.0.113.0/24", "2001:db8::/32"), such as an
        // office or a monitor. This computer always is. Comma separated in NEVELA_EXEMPT.
        'exempt' => array_values(array_filter(array_map('trim', explode(',', (string) env('NEVELA_EXEMPT', ''))))),
        // Wrong passwords, by address and account together (Security\SignInGuard). 0 switches one off.
        // captcha_after: the web sign-in asks for Turnstile (where TURNSTILE_SECRET_KEY is set).
        // lock_after: that address is locked out of that account for lock_minutes.
        // account_captcha_after: wrong passwords for one account in an hour, from anywhere, before
        // the web sign-in to it asks for Turnstile. Never a lockout: anyone could cause one.
        // stuffing_after: different accounts got wrong from one address in 10 minutes before each
        // further one is a strike against the address.
        'sign_in' => [
            'captcha_after' => env('NEVELA_SIGN_IN_CAPTCHA_AFTER', 3),
            'lock_after' => env('NEVELA_SIGN_IN_LOCK_AFTER', 10),
            'lock_minutes' => env('NEVELA_SIGN_IN_LOCK_MINUTES', 15),
            'account_captcha_after' => 20,
            'stuffing_after' => 10,
        ],
    ],

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
        | How long a sign-in lasts, as Grit has it. A sign-in is a session: the dashboard
        | holds a short access token and a refresh token, and swaps the refresh token for a
        | new pair before the access token runs out. Someone working is never interrupted;
        | a sign-in nobody uses ends; and none lasts forever. In minutes; null for no limit.
        |
        | - access: how long one access token works. A sign-out takes effect within this
        |   long on another server's copy of a token, and at once on this one.
        | - idle: unused this long (no refresh), a sign-in ends. The refresh token lasts this long.
        | - absolute: however busy, a sign-in ends this long after it began.
        |
        | A refresh token is swapped for a new one every time it is used, and an old one
        | presented again is taken as stolen: that sign-in ends on every device holding it.
        | Except within `reuse_seconds` of the swap, when it is a second request that set out
        | with the same token at the same moment, and is given an access token only.
        |
        | Tokens your own code makes with createToken() keep to idle and absolute too,
        | measured from their last use and their creation.
        */
        'session' => [
            'access' => env('NEVELA_SESSION_ACCESS', 15),
            'idle' => env('NEVELA_SESSION_IDLE', 60 * 24 * 7),
            'absolute' => env('NEVELA_SESSION_ABSOLUTE', 60 * 24 * 30),
            'reuse_seconds' => 60,
            // A sign-in to the staff's admin app (the triple tier) ends this many minutes after
            // it began, however busy: staff sign in again each working day. Customers keep
            // the limits above. The admin also signs out after a while with nothing done.
            'admin_absolute' => env('NEVELA_SESSION_ADMIN_ABSOLUTE', 60 * 12),
        ],

        /*
        | Whether staff must have two-step sign-in on to use the admin app (the triple tier).
        | Until they turn it on, the admin shows them only their account's security page.
        | Customers are never asked. Null, the default: in production only, so a new app opens
        | on your computer without it.
        */
        'staff_two_factor' => env('NEVELA_STAFF_TWO_FACTOR'),

        /*
        | Confirming it's you again, with the password, an emailed code, the authenticator app or
        | a passkey. A resource action marked "sensitive" always asks, each time. `access`: changing
        | who can sign in asks too (people, roles, deleted accounts, blocked emails), once every
        | `window` minutes on a sign-in. New apps switch it on; an older dashboard or phone app
        | can't answer the question, so turn it on once both are up to date.
        */
        'confirm' => [
            'access' => env('NEVELA_CONFIRM_ACCESS', false),
            'window' => env('NEVELA_CONFIRM_WINDOW', 5),
        ],

        /*
        | Let anyone create their own account at /sign-up. Off by default, and accounts come
        | from `php nevela user`: a generated policy lets every signed-in user do everything
        | until you tighten it, so open sign-up on an untouched app would hand the dashboard
        | to whoever finds it. Tighten app/Policies first, then set NEVELA_REGISTRATION=true.
        */
        'registration' => (bool) env('NEVELA_REGISTRATION', false),

        /*
        | Signing in with Google or GitHub (Laravel Socialite). SOCIAL_AUTH_ENABLED is the
        | switch for the whole app: off, no button shows and the routes refuse. On, a provider
        | shows once its two keys are set. The dashboard asks on every page, so the switch
        | needs no rebuild, only `php artisan config:clear` where the config is cached.
        |
        | Give the provider this address to send people back to:
        |     {NEVELA_WEB_URL}/api/auth/oauth/{provider}/callback
        |
        | A mobile app has its own way back, through the API, so a second address goes to the
        | provider as well (APP_URL is this app's public address, https):
        |     {APP_URL}/api/auth/oauth/{provider}/app
        | and `app_redirects` lists the apps it may send people on to.
        |
        | Who may come in follows `registration`. Off: only someone with an account, found by
        | the provider account connected to it or by an address the provider says is
        | verified. On: anyone, and a new account is made for a new address.
        */
        'social' => [
            'enabled' => (bool) env('SOCIAL_AUTH_ENABLED', false),
            'google' => [
                'client_id' => env('GOOGLE_CLIENT_ID'),
                'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            ],
            'github' => [
                'client_id' => env('GITHUB_CLIENT_ID'),
                'client_secret' => env('GITHUB_CLIENT_SECRET'),
            ],
            // The addresses a mobile app may be sent back to, as they start: "juice-me://". Comma
            // separated in NEVELA_APP_REDIRECTS. While APP_ENV is local, Expo Go's own is allowed too.
            'app_redirects' => array_values(array_filter(array_map('trim', explode(',', (string) env('NEVELA_APP_REDIRECTS', ''))))),
        ],

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
        'default_role' => env('NEVELA_DEFAULT_ROLE', 'USER'),

        /*
        | Where the dashboard is, for the links in emails and for passkeys, which are tied
        | to its address. In development any localhost port is accepted as well, because
        | the dashboard moves to another port when 3000 is taken.
        */
        'web_url' => env('NEVELA_WEB_URL', 'http://localhost:3000'),

        /*
        | Where the staff's admin app is, when the project has one beside the public site
        | (admin.example.com). Links to dashboard pages go there, and a passkey made there
        | is tied to its address. Null: the dashboard is at web_url.
        */
        'admin_url' => env('NEVELA_ADMIN_URL'),

        /*
        | A secret the dashboard's server and Laravel share, so Laravel can believe what the
        | dashboard says about a person's browser and address (for the list of devices).
        | Put the same value in the dashboard's .env.local. New apps get one when created.
        */
        'proxy_secret' => env('NEVELA_PROXY_SECRET'),

        /* The name an authenticator app and a passkey prompt show. Null: the app's name. */
        'issuer' => null,
    ],

    /*
    | The currency money is shown in, as its ISO 4217 code: "UGX", "USD". A money field
    | can name its own ("currency": "EUR"). Shown with the currency's own decimals, so
    | UGX 4,500,000 and $4,500,000.00. Unset, money looks as it always has. Run
    | `php nevela generate` after changing it.
    */
    'currency' => env('NEVELA_CURRENCY'),

    /*
    | Where a phone number without a + is from, as a two-letter code: UG. Set, 0772 123456 is
    | read as a Ugandan number in a `tel` field, and the dashboard's picker starts at Uganda.
    | Unset, a number needs its + and the picker starts at the viewer's own country. A number
    | that starts with + is read as what it says either way. Run `php nevela generate` after
    | changing it.
    */
    'phone_country' => env('NEVELA_PHONE_COUNTRY'),

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
    | Forms anyone can send: a resource's "public" create, with "protect" or "confirm".
    |
    | turnstile   Cloudflare's robot check. The secret key is this app's alone; the site key
    |             goes in the web app (NEXT_PUBLIC_TURNSTILE_SITE_KEY). With no secret the
    |             check is skipped in development, and in production a form that asks for
    |             it answers 503: it is never quietly left open. A check counts only if it
    |             was solved on one of `hostnames` (a name stands for its subdomains), and
    |             never on localhost in production.
    | form_lifetime  How long a form can sit open before it must be loaded again, in seconds.
    | mail        The most one address is emailed because someone typed it into a form
    |             (PublicMail::toVisitor), across every form. Past it the email is dropped
    |             and the record is still saved. This is what stops a form being used to
    |             mail strangers.
    | notify      Who is told a form was sent (PublicMail::toOwner), and how often: past
    |             max_per_hour the rest wait for one email at the top of the hour.
    | confirm     How long an address has to follow its link ("confirm"), after which the
    |             record is removed, and the page of the web app the link opens.
    */
    'public' => [
        'turnstile' => [
            'secret' => env('TURNSTILE_SECRET_KEY'),
            /* The sites a check may be solved on, with commas and no spaces between: example.com,shop.example.com. The web app's own (NEVELA_WEB_URL), unless set. */
            'hostnames' => env('TURNSTILE_HOSTNAMES'),
            'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        ],
        'form_lifetime' => 2 * 60 * 60,
        'mail' => [
            'visitor_per_hour' => 1,
            'visitor_per_day' => 3,
        ],
        'notify' => [
            'to' => env('NEVELA_NOTIFY_TO'),
            'max_per_hour' => 10,
        ],
        'confirm' => [
            'hours' => 48,
            'path' => '/confirm',
        ],
    ],

    /*
    | Rich text fields (body:richtext). Whatever is sent is parsed and written out again
    | with only these tags and attributes: nothing the editor, a script or a visitor sends
    | is trusted. A tag that isn't listed goes and its words stay. The list matches the
    | dashboard editor's toolbar; add to it only what you would let a stranger put on a page.
    | A link may use these schemes, or none (/about).
    */
    'rich_text' => [
        'tags' => ['p', 'br', 'strong', 'em', 's', 'a', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote'],
        'attributes' => ['a' => ['href']],
        'schemes' => ['http', 'https', 'mailto'],
    ],

    /*
    | The most rows POST /{slug}/_bulk creates in one request (the dashboard's "Add several"
    | grid). They are saved in one transaction, so this is also how long one can run.
    */
    'bulk_max' => 500,

    /*
    | SQLite, set up for many requests at once (Support\SqliteDefaults): writes wait up to
    | 5000 ms for each other, readers carry on while something is written (wal), and a
    | transaction takes the write lock when it starts (IMMEDIATE). These change them, for
    | every SQLite connection that doesn't set its own in config/database.php. Read here,
    | so they are kept when the config is cached.
    */
    'sqlite' => [
        'busy_timeout' => env('DB_BUSY_TIMEOUT'),
        'journal_mode' => env('DB_JOURNAL_MODE'),
        'synchronous' => env('DB_SYNCHRONOUS'),
        'transaction_mode' => env('DB_TRANSACTION_MODE'),
    ],

    /*
    | Push notifications to the phone app, through Expo (Push\Push). Every bell notice is
    | also sent as a push to the phones of the people it is for. Nothing to set for that:
    | the access token is only for an Expo project with "enhanced push security" on.
    */
    'push' => [
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

    /*
    | File and image fields (image:image, manual:file(pdf)).
    |
    | Files go on a disk from config/filesystems.php and are handed out by Nevela's own
    | route, GET {prefix}/_nevela/files/{key}, so nothing needs linking or publishing. Keys
    | are unguessable, but anyone who has one can fetch the file. If a CDN or your web
    | server serves the disk, put its address in NEVELA_UPLOADS_URL and the API's links
    | point there.
    |
    | A document that must stay private goes in a private file field ("private": true in
    | the descriptor, or cv:file(pdf|private)). It is kept on private_disk, which must not
    | be one the web server serves, and is handed out by
    | GET {prefix}/_nevela/private/{slug}/{id}/{field} to someone signed in who may view
    | that record. Its key opens nothing.
    */
    'uploads' => [
        'disk' => env('NEVELA_UPLOADS_DISK', 'public'),
        'url' => env('NEVELA_UPLOADS_URL'),

        /* Where private files are kept. Never served: not "public", and not a bucket anyone can read. */
        'private_disk' => env('NEVELA_PRIVATE_DISK', 'local'),

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
                // 16:9, as the blog's cards show it: a picture made for the list fits it whole.
                'renditions' => ['thumb' => [640, 360, 'crop']],
            ],
        ],
    ],
];
