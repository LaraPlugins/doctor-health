<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LaraPlugins API URL
    |--------------------------------------------------------------------------
    |
    | The base URL of the LaraPlugins health-check API. Override this in
    | production or during local development by setting LARAPLUGINS_DOCTOR_URL.
    |
    */

    'url' => env('LARAPLUGINS_DOCTOR_URL', 'https://laraplugins.io/api'),

    /*
    |--------------------------------------------------------------------------
    | Request timeouts
    |--------------------------------------------------------------------------
    |
    | Total request timeout and connection timeout (in seconds) for the HTTP
    | call to the LaraPlugins health-check API. Kept short so a slow or
    | unreachable endpoint does not stall `php artisan doctor`.
    |
    */

    'timeout' => 5,

    'connect_timeout' => 5,

    /*
    |--------------------------------------------------------------------------
    | HTTP retry policy
    |--------------------------------------------------------------------------
    |
    | Laravel's Http client will retry up to `times` attempts with a `sleep`
    | (in milliseconds) between attempts. `throw` is disabled so a transient
    | failure degrades to a warning instead of an exception.
    |
    */

    'retry' => [
        'times' => 2,
        'sleep' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Diagnostic behaviour
    |--------------------------------------------------------------------------
    |
    | `enabled` acts as a kill-switch for the diagnostic.
    | `include_require_dev` controls whether require-dev packages are sent.
    | `package_limit` caps how many packages are sent per run.
    | `unreachable_verdict` decides the verdict when the API cannot be reached.
    | `archived_verdict` decides the verdict for archived/abandoned packages.
    |
    */

    'enabled' => true,

    'include_require_dev' => true,

    'package_limit' => 250,

    'unreachable_verdict' => 'warn',

    'archived_verdict' => 'warn',

    /*
    |--------------------------------------------------------------------------
    | Excluded packages
    |--------------------------------------------------------------------------
    |
    | Package names that should never be sent to the API. The diagnostic itself
    | is always excluded.
    |
    */

    'exclude_packages' => [
        'laraplugins/doctor-health',
    ],

    /*
    |--------------------------------------------------------------------------
    | Extra HTTP headers
    |--------------------------------------------------------------------------
    |
    | Additional headers sent with every health-check request. Useful for
    | user-agent identification or authentication against private deployments.
    |
    */

    'http_headers' => [],
];
