<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $last_name
 * @property string|null $lastname
 * @property string|null $first_name
 * @property string|null $firstname
 * @property string|null $middle_name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
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
 * @property string|null $ContactNo
 * @property string|null $access_level
 * @property float|null $leave_balance
 * @property \Illuminate\Support\Carbon|null $date_hired
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\LeaveBalance|null $leaveBalance
 * @property string|null $department_name
 * @property string|null $dept_head_name
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function leaveBalance()
    {
        return $this->hasOne(LeaveBalance::class, 'EmpNo', 'EmpNo');
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
        ];
    }

    /**
     * Send the password reset notification.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
