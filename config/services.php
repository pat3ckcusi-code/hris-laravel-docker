<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pnpki' => [
        // Nothing PNPKI-identity-related lives here - the certificate, its
        // password, and the trust chain (root + intermediate CAs) it validates
        // against are all personal to whoever is signing, uploaded/typed
        // per-submission on the form and passed directly into the queued job
        // (see SignESignatureRequestPdfJob), never persisted in server config
        // or on disk beyond a single signing attempt.
        'tsa_url' => env('PNPKI_TSA_URL', 'https://govca.npki.gov.ph:8442/signserver/tsa?workerName=TimeStampSigner'),
        // Must match the pyHanko venv path baked into the Dockerfile's app stage.
        'pyhanko_bin' => env('PNPKI_PYHANKO_BIN', '/opt/pyhanko-venv/bin/pyhanko'),
    ],

];
