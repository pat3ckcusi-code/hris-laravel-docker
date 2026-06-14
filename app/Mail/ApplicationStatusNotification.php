<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ApplicationStatusNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $employee;

    public $application;

    public $application_type;

    public $formatted;

    public $action;

    public $notes;

    public function __construct($employee, $application, $application_type = 'Application', $formatted = [], $action = 'approved', $notes = null)
    {
        $this->employee = $employee;
        $this->application = $application;
        $this->application_type = $application_type;
        $this->formatted = $formatted;
        $this->action = $action;
        $this->notes = $notes;
    }

    public function build()
    {
        $subjectAction = $this->action === 'declined' ? 'Rejected' : 'Approved';

        return $this->subject("{$this->application_type} {$subjectAction}")
            ->view('emails.application_status_notification')
            ->with([
                'employee' => $this->employee,
                'application' => $this->application,
                'application_type' => $this->application_type,
                'formatted' => $this->formatted,
                'action' => $this->action,
                'notes' => $this->notes,
            ]);
    }
}
