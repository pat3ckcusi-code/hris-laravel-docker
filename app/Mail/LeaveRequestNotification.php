<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveRequestNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $employee;

    public $leave;

    public $formatted;

    public function __construct($employee, $leave, $formatted = [])
    {
        $this->employee = $employee;
        $this->leave = $leave;
        $this->formatted = $formatted;
    }

    public function build()
    {
        return $this->subject('New Leave Request Application')
            ->view('emails.leave_request_notification')
            ->with([
                'employee' => $this->employee,
                'leave' => $this->leave,
                'formatted' => $this->formatted,
            ]);
    }
}
