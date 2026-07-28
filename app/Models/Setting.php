<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $system_name
 * @property string $org_name
 * @property string|null $support_email
 * @property string $timezone
 * @property string $date_format
 * @property bool $records_enabled
 * @property bool $leave_enabled
 * @property bool $frontdesk_enabled
 * @property bool $payroll_enabled
 * @property bool $attendance_enabled
 * @property bool $eta_enabled
 * @property int|null $pending_alert_threshold
 * @property string|null $email_template_subject
 * @property string|null $email_template_body
 * @property string $work_start
 * @property string $lunch_return
 * @property string $work_end
 * @property string $morning_end
 * @property string $noon_end
 * @property int $payroll_working_days_per_month
 * @property int $leave_balance_decimals
 * @property string|null $mayor_name
 * @property string|null $mayor_designation
 * @property string|null $vice_mayor_name
 * @property string|null $vice_mayor_designation
 * @property string|null $hr_manager_name
 * @property string|null $hr_manager_designation
 * @property string|null $budget_officer_name
 * @property string|null $budget_officer_designation
 * @property string|null $payroll_preparer_name
 * @property string|null $payroll_preparer_designation
 * @property string|null $mail_from_address
 * @property string|null $mail_from_name
 * @property string|null $excel_sheet_password
 * @property bool $excel_protection_enabled
 * @property string $pdf_font_family
 * @property int $pdf_font_size
 * @property int $dashboard_cache_ttl
 * @property bool $auto_import_enabled
 * @property int $auto_import_interval_minutes
 * @property int|null $auto_import_dept_id
 * @property int $auto_import_page_size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder
 */
class Setting extends Model
{
    protected $table = 'settings';

    protected $fillable = [
        'system_name',
        'org_name',
        'support_email',
        'timezone',
        'date_format',
        'records_enabled',
        'leave_enabled',
        'frontdesk_enabled',
        'payroll_enabled',
        'attendance_enabled',
        'eta_enabled',
        'pending_alert_threshold',
        'email_template_subject',
        'email_template_body',
        'work_start',
        'lunch_return',
        'work_end',
        'morning_end',
        'noon_end',
        'payroll_working_days_per_month',
        'leave_balance_decimals',
        'mayor_name',
        'mayor_designation',
        'vice_mayor_name',
        'vice_mayor_designation',
        'hr_manager_name',
        'hr_manager_designation',
        'budget_officer_name',
        'budget_officer_designation',
        'payroll_preparer_name',
        'payroll_preparer_designation',
        'mail_from_address',
        'mail_from_name',
        'excel_sheet_password',
        'excel_protection_enabled',
        'pdf_font_family',
        'pdf_font_size',
        'dashboard_cache_ttl',
        'auto_import_enabled',
        'auto_import_interval_minutes',
        'auto_import_dept_id',
        'auto_import_page_size',
    ];

    protected $casts = [
        'records_enabled' => 'boolean',
        'leave_enabled' => 'boolean',
        'frontdesk_enabled' => 'boolean',
        'payroll_enabled' => 'boolean',
        'attendance_enabled' => 'boolean',
        'eta_enabled' => 'boolean',
        'excel_protection_enabled' => 'boolean',
        'auto_import_enabled' => 'boolean',
    ];
}
