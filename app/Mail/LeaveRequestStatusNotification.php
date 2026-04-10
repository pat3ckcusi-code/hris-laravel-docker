<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LeaveRequestStatusNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $employee;
    public $leave;
    public $formatted;
    public $action;
    public $notes;
    public $balances;

    public function __construct($employee, $leave, $formatted = [], $action = 'approved', $notes = null, $balances = [])
    {
        $this->employee = $employee;
        $this->leave = $leave;
        $this->formatted = $formatted;
        $this->action = $action;
        $this->notes = $notes;
        $this->balances = $balances;
    }

    public function build()
    {
        $subjectAction = $this->action === 'declined' ? 'Rejected' : 'Approved';
        return $this->subject("Leave request {$subjectAction}")
                    ->view('emails.leave_request_status_notification')
                    ->with([
                        'employee'  => $this->employee,
                        'leave'     => $this->leave,
                        'formatted' => $this->formatted,
                        'action'    => $this->action,
                        'notes'     => $this->notes,
                        'balances'  => $this->balances,
                    ]);
    }
}
