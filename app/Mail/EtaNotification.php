<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EtaNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $employee;
    public $eta;

    public function __construct($employee, $eta)
    {
        $this->employee = $employee;
        $this->eta = $eta;
    }

    public function build()
    {
        return $this->subject('New Employee Travel Authorization Application')
                    ->view('emails.eta_notification')
                    ->with([
                        'employee' => $this->employee,
                        'eta' => $this->eta,
                    ]);
    }
}
