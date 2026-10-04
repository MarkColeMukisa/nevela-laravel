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
];
