<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * CSC MC No. 04, s. 1991 "habitual" pattern: at least 2 violation periods
 * (e.g. calendar months) within the same semester (Jan-Jun / Jul-Dec), or 2
 * consecutive periods. Originally derived for Habitual Tardiness / Frequent
 * Undertime (see TimeLogsMonitoringController) and reused unchanged for DTR
 * Excuse abuse detection (see DtrExcuseController).
 */
class HabitualPatternRule
{
    /**
     * @param  Collection<int, int>  $violationMonths  sorted, unique month numbers (1-12)
     */
    public static function meets(Collection $violationMonths): bool
    {
        if ($violationMonths->count() < 2) {
            return false;
        }

        $months = $violationMonths->values()->all();
        for ($i = 0; $i < count($months) - 1; $i++) {
            if ($months[$i + 1] - $months[$i] === 1) {
                return true;
            }
        }

        $firstSemester = $violationMonths->filter(fn ($m) => $m <= 6)->count();
        $secondSemester = $violationMonths->filter(fn ($m) => $m >= 7)->count();

        return $firstSemester >= 2 || $secondSemester >= 2;
    }
}
