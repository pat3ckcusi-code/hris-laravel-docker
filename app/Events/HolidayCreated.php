<?php

namespace App\Events;

use App\Models\Holiday;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HolidayCreated
{
    use Dispatchable, SerializesModels;

    public Holiday $holiday;

    public function __construct(Holiday $holiday)
    {
        $this->holiday = $holiday;
    }
}
