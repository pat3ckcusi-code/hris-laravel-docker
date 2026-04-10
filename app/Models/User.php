<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
