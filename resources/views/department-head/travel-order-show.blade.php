@extends('dashboards.layout', [
    'title' => 'Travel Order Details',
    'subtitle' => 'View travel order details',
])

@section('content')
@php
    $creator = $order->created_by ? \App\Models\User::find($order->created_by) : null;
    $recommenderUser = $order->recommender ? \App\Models\User::find($order->recommender) : null;
    $creatorName = $creator ? trim(($creator->first_name ?? '') . ' ' . ($creator->last_name ?? '')) : 'N/A';
    $recommenderName = $recommenderUser ? trim(($recommenderUser->first_name ?? '') . ' ' . ($recommenderUser->last_name ?? '')) : 'N/A';
@endphp
<div class="card">
  <div class="card-header"><h3 class="card-title">Travel Order Details</h3></div>
  <div class="card-body">
    <div style="display:flex; gap:18px;">
      <div style="flex:1; min-width:320px">
        <table style="width:100%; border-collapse:collapse">
          <tbody>
            <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>TO Number</strong></td><td style="padding:8px; border:1px solid #f1f5f9">{{ $order->travel_order_num }}</td></tr>
            <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Destination</strong></td><td style="padding:8px; border:1px solid #f1f5f9">{{ $order->destination }}</td></tr>
            <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Departure</strong></td><td style="padding:8px; border:1px solid #f1f5f9">{{ optional($order->start_date)->format('M d, Y') ?? '-' }}</td></tr>
            <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Return</strong></td><td style="padding:8px; border:1px solid #f1f5f9">{{ optional($order->end_date)->format('M d, Y') ?? '-' }}</td></tr>
            <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Purpose</strong></td><td style="padding:8px; border:1px solid #f1f5f9">{{ $order->purpose }}</td></tr>
            <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Created By</strong></td><td style="padding:8px; border:1px solid #f1f5f9">{{ $creatorName }}</td></tr>
            <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Recommender</strong></td><td style="padding:8px; border:1px solid #f1f5f9">{{ $recommenderName }}</td></tr>
            <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Per Diem</strong></td><td style="padding:8px; border:1px solid #f1f5f9">{{ $order->per_diem ?? '-' }}</td></tr>
            <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Appropriation</strong></td><td style="padding:8px; border:1px solid #f1f5f9">{{ $order->appropriation ?? '-' }}</td></tr>
            <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Remarks</strong></td><td style="padding:8px; border:1px solid #f1f5f9">{{ $order->Remarks ?? '-' }}</td></tr>
            <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Submitted To</strong></td><td style="padding:8px; border:1px solid #f1f5f9">City Mayor's Office</td></tr>
          </tbody>
        </table>
      </div>
      <div style="width:360px">
        <h4 style="margin-top:0">Employees</h4>
        <ul style="padding-left:18px">
          @foreach($employees as $emp)
            <li>{{ $emp->last_name }}, {{ $emp->first_name }} @if($emp->designation) <span class="muted">- {{ $emp->designation }}</span>@endif</li>
          @endforeach
        </ul>
        <div style="margin-top:12px">
          <strong>Status:</strong> <span style="font-weight:700">{{ $order->status }}</span>
        </div>
        <div style="margin-top:6px">Created: {{ $order->created_at ? $order->created_at->format('M d, Y H:i') : '-' }}</div>
      </div>
    </div>
    <div style="margin-top:12px; text-align:right">
      <a class="btn btn-outline-secondary" href="{{ url('/department-head/filed-travel-orders') }}">Back</a>
    </div>
  </div>
</div>
@endsection
