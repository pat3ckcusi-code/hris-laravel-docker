<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class FileLocatorOfficialNotification extends Mailable
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
        return $this->subject('New Official File Locator Application')
                    ->view('emails.file_locator_official')
                    ->with([
                        'employee' => $this->employee,
                        'locator' => $this->locator,
                    ]);
    }
}
