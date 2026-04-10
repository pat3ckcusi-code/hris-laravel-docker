@php
    $title = 'ETA Print';
@endphp

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>ETA Print</title>
    <style>
        body { font-family: Arial, sans-serif; }
        table { width:100%; border-collapse: collapse; }
        th, td { border:1px solid #ccc; padding:6px; }
    </style>
    <script>window.onload = function(){ window.print(); }</script>
</head>
<body>
    <h1>Employee Travel Authorizations - {{ ucfirst($filter ?? 'all') }}</h1>
    <table>
        <thead>
            <tr>
                <th>Departure Date</th>
                <th>Date of Arrival</th>
                <th>Destination</th>
                <th>Purpose</th>
                <th>Purpose Details</th>
                <th>Dept Head</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        @foreach($etas as $eta)
            <tr>
                <td>{{ $eta->departure_date }}</td>
                <td>{{ $eta->arrival_date ?? '-' }}</td>
                <td>{{ $eta->destination }}</td>
                <td>{{ $eta->purpose }}</td>
                <td>{{ $eta->purpose_details ?? '-' }}</td>
                <td>{{ $deptHeadName ?? 'Not assigned' }}</td>
                <td>{{ ucfirst($eta->status) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</body>
</html>
