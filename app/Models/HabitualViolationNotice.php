<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One CSC habitual-violation notice issued against an employee for a given
 * calendar year. `offense_number` is a continuous lifetime 1-2-3 cycle per
 * (employee_id, violation_type) - never reset by calendar year - computed
 * at issuance time via nextOffenseNumber(). At most one row per
 * (employee_id, violation_type, year), enforced by a DB unique constraint.
 *
 * VIOLATION_TARDY's offense schedule is CSC's own (RACCS Schedule of
 * Penalties for Light Offenses). VIOLATION_UNDERTIME reuses the identical
 * mechanic as an internal HR tracking tool only - CSC does not classify
 * habitual undertime under the same schedule (see LEGAL_BASIS below).
 */
class HabitualViolationNotice extends Model
{
    public const VIOLATION_TARDY = 'habitual_tardy';

    public const VIOLATION_UNDERTIME = 'frequent_undertime';

    public const VALID_VIOLATION_TYPES = [self::VIOLATION_TARDY, self::VIOLATION_UNDERTIME];

    /** RACCS Schedule of Penalties for Light Offenses - "Frequent Unauthorized Tardiness." */
    public const OFFENSE_SANCTIONS = [
        1 => 'Reprimand',
        2 => 'Suspension (1-30 days)',
        3 => 'Dismissal from Service',
    ];

    public const LEGAL_BASIS = [
        self::VIOLATION_TARDY => 'CSC MC No. 04, s. 1991 (definition of habitual tardiness); Rules on Administrative Cases in the Civil Service, Schedule of Penalties for Light Offenses (1st: Reprimand, 2nd: Suspension 1-30 days, 3rd: Dismissal).',
        self::VIOLATION_UNDERTIME => 'Internal HR policy mirroring the CSC habitual-tardiness threshold (MC No. 04, s. 1991) and its offense schedule for tracking purposes only. CSC itself classifies habitual undertime separately as Simple Misconduct and/or Conduct Prejudicial to the Best Interest of the Service, which requires a formal administrative case rather than an automatic notice.',
    ];

    protected $fillable = [
        'employee_id',
        'violation_type',
        'year',
        'offense_number',
        'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'offense_number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if (! in_array($model->violation_type, self::VALID_VIOLATION_TYPES, true)) {
                throw new \InvalidArgumentException(
                    "Invalid violation_type '{$model->violation_type}'."
                );
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Continuous lifetime 1->2->3->1... cycle per (employee, violation
     * type). Caller must invoke this from within a transaction that has
     * already locked a synchronization row (see
     * TimeLogsMonitoringController::issueNotice()), otherwise two
     * near-simultaneous issuances could read the same count.
     */
    public static function nextOffenseNumber(int $employeeId, string $violationType): int
    {
        $priorCount = static::where('employee_id', $employeeId)
            ->where('violation_type', $violationType)
            ->count();

        return ($priorCount % 3) + 1;
    }
}
