@props(['status'])

@php
    $badgeClass = match(strtolower((string) $status)) {
        'pending' => 'badge-pending',
        'approved' => 'badge-approved',
        'rejected' => 'badge-rejected',
        'declined' => 'badge-rejected',
        'cancelled' => 'badge-cancelled',
        default => 'badge-default',
    };
    
    $displayStatus = match(strtolower((string) $status)) {
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'declined' => 'Declined',
        'cancelled' => 'Cancelled',
        default => ucfirst($status),
    };
@endphp

<span class="hris-badge {{ $badgeClass }}">{{ $displayStatus }}</span>
