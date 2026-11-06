<?php

declare(strict_types=1);

/*
 * Google Identity Services. The client ID is public by design; there is no
 * client secret in the ID-token sign-in flow, and none may be stored here.
 */
return [
    'client_id' => (string) env('GOOGLE_CLIENT_ID', ''),
    'allowed_hd' => array_values(array_filter(array_map('trim', explode(',', (string) env('GOOGLE_ALLOWED_HD', ''))))),
    'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
    'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
];
