<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property bool $records_enabled
 * @property bool $leave_enabled
 * @property bool $frontdesk_enabled
 * @property int|null $pending_alert_threshold
 * @property string|null $email_template_subject
 * @property string|null $email_template_body
 * @property string|null $mayor_name
 * @property string|null $mayor_designation
 * @property string|null $vice_mayor_name
 * @property string|null $vice_mayor_designation
 * @property string|null $hr_manager_name
 * @property string|null $hr_manager_designation
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
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
