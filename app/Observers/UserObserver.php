<?php

namespace App\Observers;

use App\Models\LeaveBalance;
use App\Models\User;

class UserObserver
{
    /**
     * Handle the User "created" event.
     *
     * Automatically inserts a leave_balances row with configurable defaults
     * whenever a new user is created, regardless of role.
     */
    public function created(User $user): void
    {
        $defaults = config('hris.default_leave_balances', []);

        LeaveBalance::create(array_merge(
            ['user_id' => $user->id],
            $defaults,
        ));
    }
}
