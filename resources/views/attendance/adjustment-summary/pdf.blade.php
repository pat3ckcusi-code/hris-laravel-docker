<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Attendance Adjustment Summary</title>
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #0f172a; }
        h2 { text-align: center; margin-bottom: 2px; }
        p.subtitle { text-align: center; color: #475569; margin-top: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 4px 6px; }
        th { background: #bdd7ee; text-align: center; }
        td.center { text-align: center; }
    </style>
</head>
<body>
    <h2>Attendance Adjustment Summary</h2>
    <p class="subtitle">{{ $departmentLabel }} &middot; {{ \Carbon\Carbon::createFromDate($year, $month, 1)->format('F Y') }}</p>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Employee No.</th>
                <th>Name</th>
                <th>Department</th>
                <th>Position</th>
                <th>Employee Type</th>
                <th>Unfiled Leave</th>
                <th>Tardiness (Count)</th>
                <th>Tardiness (Min)</th>
                <th>Undertime (Count)</th>
                <th>Undertime (Min)</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $row)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td class="center">{{ $row['emp_no'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td>{{ $row['department'] }}</td>
                    <td>{{ $row['position'] }}</td>
                    <td class="center">{{ $row['employee_type'] }}</td>
                    <td class="center">{{ $row['unfiled_count'] }}</td>
                    <td class="center">{{ $row['tardiness_count'] }}</td>
                    <td class="center">{{ $row['tardiness_minutes'] }}</td>
                    <td class="center">{{ $row['undertime_count'] }}</td>
                    <td class="center">{{ $row['undertime_minutes'] }}</td>
                    <td class="center">{{ $row['status'] }}</td>
                </tr>
            @empty
                <tr><td colspan="12" class="center">No records for the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
