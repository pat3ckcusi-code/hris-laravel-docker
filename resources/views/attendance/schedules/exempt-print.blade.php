<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DTR / Biometric Exemption List</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        @page { size: 8.5in 11in; margin: 0.7in; }

        body {
            font-family: 'Times New Roman', serif;
            font-size: 11pt;
            color: #000;
            background: #f1f5f9;
            line-height: 1.5;
        }

        .print-container {
            max-width: 8.5in;
            margin: 24px auto;
            background: #fff;
            padding: 48px 56px;
            box-shadow: 0 2px 10px rgba(0,0,0,.12);
        }

        .no-print { margin: 16px auto; max-width: 8.5in; text-align: center; }
        .no-print button {
            padding: 10px 18px; margin: 0 4px;
            border: 1px solid #d1d5db; border-radius: 4px;
            cursor: pointer; font-size: 14px;
        }
        .btn-print { background: #2563eb; color: #fff; border-color: #2563eb; }
        .btn-excel { background: #16a34a; color: #fff; border-color: #16a34a; }
        .btn-close { background: #6b7280; color: #fff; border-color: #6b7280; }

        .list-title { font-weight: bold; font-size: 14pt; text-align: center; margin-bottom: 4px; text-transform: uppercase; }
        .list-subtitle { text-align: center; margin-bottom: 24px; color: #333; }

        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #e5e7eb; font-size: 10.5pt; }
        td { font-size: 10.5pt; }

        .empty-state { text-align: center; padding: 32px 0; color: #555; }

        @media print {
            body { background: #fff; }
            .no-print { display: none; }
            .print-container { margin: 0; max-width: 100%; box-shadow: none; padding: 0; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">Print</button>
    <button class="btn-excel" onclick="window.location='{{ route('attendance.schedules.exempt-report.excel', request()->only(['dept_id', 'employee_type', 'search', 'status'])) }}'">Export Excel</button>
    <button class="btn-close" onclick="window.close()">Close</button>
</div>

<div class="print-container">
    <div class="list-title">DTR / Biometric Exemption Log</div>
    <div class="list-subtitle">
        {{ ['all' => 'All records', 'active' => 'Currently active only', 'expired' => 'Expired only'][$status] ?? 'All records' }}
        &middot; Generated {{ now()->toFormattedDateString() }}
    </div>

    @if ($periods->isEmpty())
        <div class="empty-state">No DTR exemption records found for the selected filters.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>EmpNo</th>
                    <th>Department</th>
                    <th>Position</th>
                    <th>Effective Date</th>
                    <th>Date Until</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($periods as $period)
                    <tr>
                        <td>{{ trim("{$period->user->last_name}, {$period->user->first_name}") }}</td>
                        <td>{{ $period->user->EmpNo }}</td>
                        <td>{{ $period->user->department?->Dept_name ?? '-' }}</td>
                        <td>{{ $period->user->designation ?? '-' }}</td>
                        <td>{{ $period->effective_date?->format('M d, Y') ?? '-' }}</td>
                        <td>{{ $period->until_date?->format('M d, Y') ?? '-' }}</td>
                        <td>{{ $period->reason ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

</body>
</html>
