@extends('dashboards.layout', ['title' => 'Leave Print', 'subtitle' => 'Print preview'])

@section('content')
<div style="max-width:760px;margin:36px auto;font-family:sans-serif">
    <h2>Leave Print (Fallback)</h2>
    <p>The PDF template is not available. Below are the leave details you requested to print.</p>

    @php $lv = $leaves->first(); @endphp

    <table style="width:100%;border-collapse:collapse">
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #ddd">{{ optional($lv->user)->name ?? '—' }}</td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Leave Type</strong></td><td style="padding:8px;border:1px solid #ddd">{{ $lv->leave_type }}</td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Period</strong></td><td style="padding:8px;border:1px solid #ddd">{{ optional($lv->start_date) ? \Carbon\Carbon::parse($lv->start_date)->format('M d, Y') : '—' }} to {{ optional($lv->end_date) ? \Carbon\Carbon::parse($lv->end_date)->format('M d, Y') : '—' }}</td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Total Days</strong></td><td style="padding:8px;border:1px solid #ddd">{{ $lv->total_days ?? '—' }}</td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Approved At</strong></td><td style="padding:8px;border:1px solid #ddd">{{ $lv->updated_at ? $lv->updated_at->format('M d, Y') : '—' }}</td></tr>
        <tr><td style="padding:8px;border:1px solid #ddd"><strong>Reason</strong></td><td style="padding:8px;border:1px solid #ddd">{{ $lv->reason ?? '—' }}</td></tr>
    </table>

    <div style="margin-top:16px;text-align:right">
        <button onclick="window.print()" style="padding:8px 12px;border-radius:6px">Print</button>
        <a href="{{ url()->previous() }}" style="margin-left:8px">Back</a>
    </div>
</div>
@endsection
