<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Lets the browser read the byte-range headers of the chat file proxy (PDF viewer loads pages on demand).
    'exposed_headers' => ['Content-Range', 'Accept-Ranges', 'Content-Length'],

    // Browsers may remember a successful pre-flight check for up to 2 hours (saves a round trip on every API call).
    'max_age' => 7200,

    'supports_credentials' => false,

];
