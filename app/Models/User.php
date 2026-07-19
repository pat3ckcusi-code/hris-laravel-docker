<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use App\Services\DepartmentService;
use App\Support\RoleNormalizer;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $last_name
 * @property string|null $lastname
 * @property string|null $first_name
 * @property string|null $firstname
 * @property string|null $middle_name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $force_password_change
 * @property string|null $remember_token
 * @property string|null $EmpNo
 * @property string|null $UserName
 * @property string|null $AcctName
 * @property string|null $designation
 * @property int|null $Dept_id
 * @property string|null $Status
 * @property string|null $employee_type
 * @property bool $is_sanggunian_member
 * @property bool $on_extended_service
 * @property float|null $hours_per_day
 * @property int|null $shift_id
 * @property bool $dtr_exempt
 * @property string|null $ContactNo
 * @property string|null $access_level
 * @property float|null $leave_balance
 * @property Carbon|null $date_hired
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LeaveBalance|null $leaveBalance
 * @property-read string $full_name
 * @property string|null $department_name
 * @property string|null $dept_head_name
 *
 * @mixin Builder
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    public function leaveBalance()
    {
        return $this->hasOne(LeaveBalance::class, 'user_id', 'id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class, 'Dept_id', 'Dept_id');
    }

    /**
     * True when this employee must keep reporting normally during a declared
     * work suspension - either flagged individually, or a member of a
     * frontline/essential department (health, disaster response, security,
     * etc.). Consulted everywhere WorkSchedule::applySuspension() would
     * otherwise apply.
     */
    public function isFrontlineExempt(): bool
    {
        return (bool) $this->is_frontline || (bool) $this->department?->is_frontline;
    }

    public function oicAssignments()
    {
        return $this->hasMany(OicAssignment::class, 'user_id', 'id');
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'last_name',
        'first_name',
        'middle_name',
        'name_extension',
        'email',
        'password',
        'leave_balance',
        'date_hired',
        'employee_type',
        'is_sanggunian_member',
        'on_extended_service',
        'hours_per_day',
        'salary_grade',
        'salary_step',
        'shift_id',
        'dtr_exempt',
        'is_frontline',
    ];

    /**
     * Employee types eligible to file leave requests.
     */
    public const LEAVE_ELIGIBLE_TYPES = [
        'permanent',
        'elected officials',
        'co-terminus',
    ];

    /**
     * Check if the user's employee type is eligible to file leave requests.
     */
    public function canFileLeave(): bool
    {
        $type = strtolower(trim((string) ($this->employee_type ?? '')));

        return in_array($type, self::LEAVE_ELIGIBLE_TYPES, true);
    }

    public const STATUS_ACTIVE = 'Active';

    public const STATUS_INACTIVE = 'Inactive';

    public const STATUS_SEPARATED = 'Separated';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_SEPARATED];

    /**
     * Employees with no Status set are legacy records and are treated as active.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where('Status', self::STATUS_ACTIVE)
                ->orWhereNull('Status')
                ->orWhere('Status', '');
        });
    }

    public function isActive(): bool
    {
        return ! $this->isInactive() && ! $this->isSeparated();
    }

    public function isInactive(): bool
    {
        return strtolower(trim((string) $this->Status)) === strtolower(self::STATUS_INACTIVE);
    }

    public function isSeparated(): bool
    {
        return strtolower(trim((string) $this->Status)) === strtolower(self::STATUS_SEPARATED);
    }

    /**
     * Check if the user can access the Shift Templates/Assignment/Schedule screens.
     * Time Keeper and HR Manager always can. Department Head/Administrative Officer
     * only if at least one department they head (or cover via OIC) has an active
     * ShiftManagementGrant - access is per-department, granted by a Time Keeper.
     */
    public function hasShiftManagementAccess(): bool
    {
        $role = RoleNormalizer::normalize((string) ($this->access_level ?? ''));

        if (in_array($role, ['time keeper', 'hr manager'], true)) {
            return true;
        }

        if (! in_array($role, ['department head', 'administrative officer'], true)) {
            return false;
        }

        $departmentService = app(DepartmentService::class);
        $depts = $role === 'administrative officer'
            ? $departmentService->resolveAllDepartmentsForAdminOfficer($this)
            : $departmentService->resolveAllDepartmentsForUser($this);

        return ShiftManagementGrant::active()->whereIn('dept_id', $depts->pluck('Dept_id'))->exists();
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'date_hired' => 'date',
            'date_of_original_appointment' => 'date',
            'date_of_last_promotion' => 'date',
            'is_sanggunian_member' => 'boolean',
            'on_extended_service' => 'boolean',
            'hours_per_day' => 'float',
            'dtr_exempt' => 'boolean',
            'is_frontline' => 'boolean',
        ];
    }

    /**
     * The work-shift template assigned to this employee. A null shift means the
     * employee follows the global standard-day shift from the settings table.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function shiftSchedules(): HasMany
    {
        return $this->hasMany(EmployeeShiftSchedule::class);
    }

    /** Full effective-dated shift-assignment history (past, current, and scheduled). */
    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    /**
     * Send the password reset notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
