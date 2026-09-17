<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Scoped to the API and the Sanctum CSRF-cookie endpoint. Because the SPA
    | authenticates with cookies, `supports_credentials` is true and the
    | allowed origins must be an explicit allowlist (never "*").
    |
    | DEPLOYMENT NOTE — keep this in sync with config/sanctum.php. This list
    | (env CORS_ALLOWED_ORIGINS) and Sanctum's `stateful` list (env
    | SANCTUM_STATEFUL_DOMAINS) MUST name the same SPA origin(s) in production.
    | An origin in only this list gets credentialed CORS but no Sanctum session
    | middleware, so cookie auth silently fails. Add every production SPA origin
    | to BOTH env vars.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost,http://localhost:3000,http://localhost:5173,http://127.0.0.1:8000',
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
