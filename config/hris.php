<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Leave Balance Values
    |--------------------------------------------------------------------------
    |
    | These values are used when a new user is created and a corresponding
    | leave balance record is automatically inserted. Each key maps to a
    | column in the leave_balances table.
    |
    */

    'default_leave_balances' => [
        'VL'   => 0, // Vacation Leave
        'SL'   => 0, // Sick Leave
        'WLNS' => 0, // Wellness Leave
        'SPL'  => 0, // Solo Parent Leave
        'CTO'  => 0, // Compensatory Time Off
        'SP'   => 0, // Special Leave Privilege
    ],

];
