<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Document Request Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 24px;
            color: #0f172a;
        }

        h1 {
            margin-bottom: 6px;
        }

        .meta {
            margin-bottom: 18px;
            color: #475569;
            font-size: 13px;
        }

        .summary {
            display: flex;
            gap: 12px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .summary-box {
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            min-width: 120px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #cbd5e1;
            padding: 8px;
            text-align: left;
            vertical-align: top;
            font-size: 12px;
        }

        thead th {
            background: #f8fafc;
        }

        @media print {
            body {
                margin: 12px;
            }
        }
    </style>
</head>
<body>
    <h1>Document Request Report</h1>
    <div class="meta">
        Generated on {{ now()->format('M d, Y h:i A') }}
        | Scope: {{ ucfirst($filters['scope'] ?? 'all') }}
        | Date: {{ $filters['date'] ?: 'All' }}
        | Month: {{ $filters['month'] ?: 'All' }}
        | Document Type: {{ $filters['document_type'] ?: 'All' }}
        | Status: {{ $filters['status'] ?: 'All' }}
    </div>

    <div class="summary">
        <div class="summary-box"><strong>Total</strong><br>{{ $summary['total'] }}</div>
        <div class="summary-box"><strong>Pending</strong><br>{{ $summary['pending'] }}</div>
        <div class="summary-box"><strong>Approved</strong><br>{{ $summary['approved'] }}</div>
        <div class="summary-box"><strong>Completed</strong><br>{{ $summary['completed'] }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Emp No.</th>
                <th>Employee Name</th>
                <th>Department</th>
                <th>Document Type</th>
                <th>Purpose</th>
                <th>Requested On</th>
                <th>Status</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($requests as $requestItem)
                <tr>
                    <td>{{ $requestItem['emp_no'] }}</td>
                    <td>{{ $requestItem['employee_name'] }}</td>
                    <td>{{ $requestItem['department'] }}</td>
                    <td>{{ $requestItem['document_type'] }}</td>
                    <td>{{ $requestItem['purpose'] }}</td>
                    <td>{{ $requestItem['requested_on'] }}</td>
                    <td>{{ $requestItem['status'] }}</td>
                    <td>{{ $requestItem['remarks'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">No requests found for the selected filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
</body>
</html>
