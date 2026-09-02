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

    // The vendor's GetTimeLogsBulkData endpoint does not paginate reliably
    // when 'start' > 0 across multiple calls - confirmed 2026-09-02: a
    // multi-page walk both silently drops some employees' records entirely
    // and duplicates others across page boundaries, with a normal 200 OK
    // and no error signal. Default set high enough (20,000) that a single
    // day's company-wide fetch (observed peak ~2,900 records) always
    // completes in one call, avoiding the vendor's broken pagination
    // entirely rather than working around it. See PersonnelLogImportService
    // for the duplicate-detection safety net in case this is ever exceeded.
    'logs_page_size' => (int) env('INTEGRATION_API_LOGS_PAGE_SIZE', 20000),

];
