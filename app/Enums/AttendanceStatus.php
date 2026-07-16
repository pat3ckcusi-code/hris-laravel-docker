<?php

namespace App\Enums;

/**
 * Resolved attendance status for a single DTR day.
 *
 * The first eight cases are written to dtrs.status by the import path
 * (AttendanceStatusResolver). The remaining four are READ-TIME-ONLY
 * classifications: Absent stays derived from the absence of a dtrs row
 * (or a manual is_absent entry), and Leave/Holiday/Official Business are
 * overlaid by the display layer from their own tables - the import path
 * must never persist them.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Undertime = 'undertime';
    case HalfDayAm = 'half_day_am';
    case HalfDayPm = 'half_day_pm';
    case MissingIn = 'missing_in';
    case MissingOut = 'missing_out';
    case Incomplete = 'incomplete';

    // Read-time-only members (never written by the import path).
    case Absent = 'absent';
    case OfficialBusiness = 'official_business';
    case Leave = 'leave';
    case Holiday = 'holiday';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late => 'Late',
            self::Undertime => 'Undertime',
            self::HalfDayAm => 'Half Day AM',
            self::HalfDayPm => 'Half Day PM',
            self::MissingIn => 'Missing IN',
            self::MissingOut => 'Missing OUT',
            self::Incomplete => 'Incomplete Logs',
            self::Absent => 'Absent',
            self::OfficialBusiness => 'Official Business',
            self::Leave => 'Leave',
            self::Holiday => 'Holiday',
        };
    }
}
