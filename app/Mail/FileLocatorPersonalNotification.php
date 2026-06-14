<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FileLocatorPersonalNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $employee;

    public $locator;

    public function __construct($employee, $locator)
    {
        $this->employee = $employee;
        $this->locator = $locator;
    }

    public function build()
    {
        return $this->subject('New Personal File Locator Application')
            ->view('emails.file_locator_personal')
            ->with([
                'employee' => $this->employee,
                'locator' => $this->locator,
            ]);
    }
}
