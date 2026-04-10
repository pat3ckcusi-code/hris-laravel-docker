<?php

namespace App\Listeners;

use App\Events\HolidayCreated;
use App\Services\HolidayLeaveCancellationService;

class CancelLeavesOnHoliday
{
    protected HolidayLeaveCancellationService $service;

    public function __construct(HolidayLeaveCancellationService $service)
    {
        $this->service = $service;
    }

    public function handle(HolidayCreated $event): void
    {
        $holiday = $event->holiday;

        $reason = 'Cancelled by Holiday: ' . $holiday->title
            . ' (' . $holiday->holiday_date->format('M d, Y') . ')';

        $this->service->cancelLeavesOnDate(
            $holiday->holiday_date->format('Y-m-d'),
            $reason,
            $holiday->created_by
        );
    }
}
