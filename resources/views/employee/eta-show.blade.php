@extends('dashboards.layout')

@php
    $title = 'ETA Details';
    $subtitle = 'View filed Employee Travel Authorization (ETA)';
@endphp

@section('content')
    <div class="tile">
        <h2 style="margin-top:0">ETA Details</h2>

        <table style="width:100%; border-collapse:collapse; margin-bottom:12px">
            <tr>
                <th style="text-align:left; padding:8px; border:1px solid #e2e8f0; width:200px">Departure Date</th>
                <td style="padding:8px; border:1px solid #e2e8f0">{{ $eta->departure_date }}</td>
            </tr>
            <tr>
                <th style="text-align:left; padding:8px; border:1px solid #e2e8f0">Date of Arrival</th>
                <td style="padding:8px; border:1px solid #e2e8f0">{{ $eta->arrival_date ?? '-' }}</td>
            </tr>
            <tr>
                <th style="text-align:left; padding:8px; border:1px solid #e2e8f0">Destination</th>
                <td style="padding:8px; border:1px solid #e2e8f0">{{ $eta->destination }}</td>
            </tr>
            <tr>
                <th style="text-align:left; padding:8px; border:1px solid #e2e8f0">Purpose</th>
                <td style="padding:8px; border:1px solid #e2e8f0">{{ $eta->purpose }}</td>
            </tr>
            @if(!empty($eta->purpose_details))
            <tr>
                <th style="text-align:left; padding:8px; border:1px solid #e2e8f0">Purpose Details</th>
                <td style="padding:8px; border:1px solid #e2e8f0">{{ $eta->purpose_details }}</td>
            </tr>
            @endif
            <tr>
                <th style="text-align:left; padding:8px; border:1px solid #e2e8f0">Department Head</th>
                <td style="padding:8px; border:1px solid #e2e8f0">{{ $deptHeadName ?? 'Not assigned' }}</td>
            </tr>
            <tr>
                <th style="text-align:left; padding:8px; border:1px solid #e2e8f0">Status</th>
                <td style="padding:8px; border:1px solid #e2e8f0">{{ ucfirst($eta->status) }}</td>
            </tr>
            <tr>
                <th style="text-align:left; padding:8px; border:1px solid #e2e8f0">Filed At</th>
                <td style="padding:8px; border:1px solid #e2e8f0">{{ $eta->created_at->toDateTimeString() }}</td>
            </tr>
        </table>

        <div style="display:flex; gap:8px">
            <a class="btn" href="{{ route('dashboard.employee.eta') }}">Back</a>
            @if($eta->status === 'approved')
                <a class="btn" href="{{ route('employee.eta.print.single', ['eta' => $eta->id]) }}" target="_blank">Print</a>
            @endif
        </div>
    </div>
@endsection
