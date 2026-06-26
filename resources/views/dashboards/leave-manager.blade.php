@extends('dashboards.layout', [
    'title'    => 'Leave Manager Dashboard',
    'subtitle' => 'Monitor leave activity, balances, and cancellation requests.',
])

@section('page_head')
<style>
.tile--link { text-decoration: none; color: inherit; display: block; }
.tile--link:hover { border-color: #94a3b8; box-shadow: 0 2px 8px rgba(0,0,0,.06); transition: border-color .15s, box-shadow .15s; }
.tile-icon  { font-size: 1.3rem; margin-bottom: 6px; opacity: .7; }
.tile-count { display: block; font-size: 2rem; font-weight: 700; color: #0f172a; margin: 6px 0 4px; line-height: 1; }
.tile-count--urgent  { color: #dc2626; }
.tile-count--neutral { color: #64748b; font-size: 1.5rem; }
.tile-count--green   { color: #16a34a; }
.tile-count--blue    { color: #3b82f6; }
.tile-desc  { font-size: .82rem; color: #64748b; margin: 0; line-height: 1.4; }
</style>
@endsection

{{-- ── Summary tiles ──────────────────────────────────────────────────── --}}
@section('tiles')

    {{-- Filed this year --}}
    <a href="{{ route('leave-manager.approved-leaves') }}" class="tile tile--link tile--blue">
        <div class="tile-icon"><i class="fas fa-file-alt"></i></div>
        <strong>Filed This Year</strong>
        <span class="tile-count tile-count--blue">{{ $totalFiled }}</span>
        <p class="tile-desc">Total leave applications filed in {{ now()->year }}.</p>
    </a>

    {{-- Approved --}}
    <a href="{{ route('leave-manager.approved-leaves') }}" class="tile tile--link tile--green">
        <div class="tile-icon"><i class="fas fa-check-circle"></i></div>
        <strong>Approved</strong>
        <span class="tile-count tile-count--green">{{ $approvedCount }}</span>
        <p class="tile-desc">Approved leave requests this year.</p>
    </a>

    {{-- Pending Cancellations --}}
    <a href="{{ route('leave-manager.employee-cancellation-requests') }}" class="tile tile--link {{ $pendingCancellationCount > 0 ? 'tile--warning' : '' }}">
        <div class="tile-icon"><i class="fas fa-undo-alt"></i></div>
        <strong>Pending Cancellations</strong>
        <span class="tile-count {{ $pendingCancellationCount > 0 ? 'tile-count--urgent' : '' }}">{{ $pendingCancellationCount }}</span>
        <p class="tile-desc">Cancellation requests awaiting your decision.</p>
    </a>

    {{-- Cancelled --}}
    <div class="tile">
        <div class="tile-icon"><i class="fas fa-ban"></i></div>
        <strong>Cancelled</strong>
        <span class="tile-count tile-count--neutral">{{ $cancelledCount }}</span>
        <p class="tile-desc">Leave requests cancelled so far this year.</p>
    </div>

    {{-- Employee Records --}}
    <a href="{{ route('leave-manager.manage-balance') }}" class="tile tile--link">
        <div class="tile-icon"><i class="fas fa-users"></i></div>
        <strong>Employee Records</strong>
        <span class="tile-count">{{ $employeeBalanceCount }}</span>
        <p class="tile-desc">Employees with leave balance records on file.</p>
    </a>

    {{-- Low Balance --}}
    <a href="{{ route('leave-manager.manage-balance') }}" class="tile tile--link {{ $lowBalanceCount > 0 ? 'tile--warning' : '' }}">
        <div class="tile-icon"><i class="fas fa-exclamation-triangle"></i></div>
        <strong>Low Balance Alert</strong>
        <span class="tile-count {{ $lowBalanceCount > 0 ? 'tile-count--urgent' : '' }}">{{ $lowBalanceCount }}</span>
        <p class="tile-desc">Employees with VL or SL ≤ 5 days remaining.</p>
    </a>

@endsection

{{-- ── Analytics & Tables ────────────────────────────────────────────── --}}
@section('content')
<div class="lm-dashboard">

    {{-- Anomaly alert --}}
    @if($anomalyDept)
    <div class="lm-alert-banner">
        <i class="fas fa-exclamation-triangle"></i>
        <span>
            <strong>Anomaly Detected:</strong>
            <strong>{{ $anomalyDept['name'] }}</strong> recorded
            <strong>{{ $anomalyDept['count'] }} sick leave requests</strong> in the last 3 months - unusually high.
            Consider reviewing absenteeism patterns for this department.
        </span>
    </div>
    @endif

    {{-- Critical Leave Balances --}}
    <div class="lm-section">
        <div class="lm-section-header">
            <h3>
                <i class="fas fa-battery-quarter" style="margin-right:6px;color:#dc2626"></i>
                Critical Leave Balances
                <span style="font-size:0.78rem;font-weight:400;color:#64748b;">(VL &lt; 2 days or SL &lt; 2 days)</span>
            </h3>
        </div>
        <x-critical-balances-table :balances="$criticalBalances" />
    </div>

</div>
@endsection

