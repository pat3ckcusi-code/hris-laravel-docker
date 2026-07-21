<?php

namespace App\Models;

use App\Support\WorkSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $application_type
 * @property string|null $location
 * @property string|null $travel_date
 * @property string|null $intended_departure_time
 * @property string|null $intended_arrival_time
 * @property string|null $detail
 * @property string|null $actual_arrival_time
 * @property string $status
 * @property int|null $approved_by
 * @property string|null $approved_role
 * @property Carbon|null $approved_at
 * @property string|null $cancellation_status
 * @property string|null $cancellation_reason
 * @property string|null $cancellation_review_remarks
 * @property Carbon|null $cancellation_requested_at
 * @property Carbon|null $cancellation_reviewed_at
 * @property int|null $cancellation_requested_by
 * @property int|null $cancellation_reviewed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @mixin Builder
 */
class Locator extends Model
{
    use HasFactory;

    protected $table = 'locators';

    protected $fillable = [
        'user_id',
        'application_type',
        'location',
        'travel_date',
        'intended_departure_time',
        'intended_arrival_time',
        'detail',
        'actual_arrival_time',
        'status',
        'cancelled_by',
        'cancelled_at',
        'cancellation_remarks',
        'cancellation_status',
        'cancellation_reason',
        'cancellation_review_remarks',
        'cancellation_requested_at',
        'cancellation_reviewed_at',
        'cancellation_requested_by',
        'cancellation_reviewed_by',
    ];

    protected $casts = [
        'travel_date' => 'date',
        'intended_departure_time' => 'string',
        'intended_arrival_time' => 'string',
        'actual_arrival_time' => 'string',
        'cancelled_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancellation_requested_at' => 'datetime',
        'cancellation_reviewed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancellationRequestedBy()
    {
        return $this->belongsTo(User::class, 'cancellation_requested_by');
    }

    public function cancellationReviewedBy()
    {
        return $this->belongsTo(User::class, 'cancellation_reviewed_by');
    }

    /**
     * DTR slot keys ('am_in'|'am_out'|'pm_in'|'pm_out') this travel window covers,
     * relative to the employee's actual work schedule for that date. Single source
     * of truth for the DTR display map (web + Excel export) AND DtrPunchResolver's
     * excluded-slot exclusion at write time - both sides must agree, or a slot can
     * end up marked "covered" for display while the resolver didn't exclude it (or
     * vice versa), causing a real punch to be hidden behind "LOCATOR" or shown in
     * the wrong column.
     *
     * @return array<int, string>
     */
    public static function coveredSlotKeys(string $departure, string $arrival, WorkSchedule $schedule): array
    {
        $dep = substr($departure, 0, 5);
        $arr = substr($arrival, 0, 5);

        return array_values(array_filter([
            $dep <= $schedule->workStart ? 'am_in' : null,
            ($dep <= '12:00' && $arr >= $schedule->morningEnd) ? 'am_out' : null,
            ($dep <= $schedule->lunchReturn && $arr >= $schedule->lunchReturn) ? 'pm_in' : null,
            $arr >= $schedule->workEnd ? 'pm_out' : null,
        ]));
    }

    /**
     * Same coverage as coveredSlotKeys(), but paired with the [departure, arrival]
     * exclusion window for each covered slot instead of a bare slot key - a punch
     * outside this window (before departure, or after arrival) is real and should
     * never be excluded from its natural slot. DtrPunchResolver is the consumer:
     * it only treats a covered slot as "no real punch expected" while a candidate
     * punch falls inside this window.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function coveredSlotWindows(string $departure, string $arrival, WorkSchedule $schedule): array
    {
        $window = [substr($departure, 0, 5), substr($arrival, 0, 5)];

        return array_fill_keys(self::coveredSlotKeys($departure, $arrival, $schedule), $window);
    }
}
