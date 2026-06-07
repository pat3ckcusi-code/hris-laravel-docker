<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Time-log integration API (external biometric / HR system)
    |--------------------------------------------------------------------------
    */

    'base_url' => rtrim(env('INTEGRATION_API_BASE_URL', ''), '/'),

    'username' => env('INTEGRATION_API_USERNAME'),

    'password' => env('INTEGRATION_API_PASSWORD'),

    'token_path' => env('INTEGRATION_API_TOKEN_PATH', '/api/Integration/GetToken'),

    'logs_path' => env('INTEGRATION_API_LOGS_PATH', '/api/Integration/GetTimeLogsBulkData'),

    'timeout_token' => (int) env('INTEGRATION_API_TOKEN_TIMEOUT', 15),

    'timeout_logs' => (int) env('INTEGRATION_API_LOGS_TIMEOUT', 30),

    'logs_page_size' => (int) env('INTEGRATION_API_LOGS_PAGE_SIZE', 1000),

];
