<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = [
        'records_enabled',
        'leave_enabled',
        'frontdesk_enabled',
        'pending_alert_threshold',
        'email_template_subject',
        'email_template_body',
        'mayor_name',
        'mayor_designation',
        'vice_mayor_name',
        'vice_mayor_designation',
        'hr_manager_name',
        'hr_manager_designation',
    ];

    protected $casts = [
        'records_enabled'   => 'boolean',
        'leave_enabled'     => 'boolean',
        'frontdesk_enabled' => 'boolean',
    ];
}
