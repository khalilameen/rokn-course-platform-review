<?php

return [
    'stream_api_key' => env('BUNNY_STREAM_API_KEY'),
    'library_id' => env('BUNNY_STREAM_LIBRARY_ID'),
    'cdn_hostname' => env('BUNNY_CDN_HOSTNAME'),
    // Optional second streaming hostname/pull zone. It must serve the same
    // Bunny Stream library and use the same directory token-auth key.
    'fallback_cdn_hostname' => env('BUNNY_FALLBACK_CDN_HOSTNAME'),
    'storage_zone' => env('BUNNY_STORAGE_ZONE'),
    'storage_password' => env('BUNNY_STORAGE_PASSWORD'),
    'token_auth_key' => env('BUNNY_TOKEN_AUTH_KEY'),
    'connect_timeout_seconds' => (int) env('BUNNY_CONNECT_TIMEOUT_SECONDS', 15),
    'upload_timeout_seconds' => (int) env('BUNNY_UPLOAD_TIMEOUT_SECONDS', 3600),
];
