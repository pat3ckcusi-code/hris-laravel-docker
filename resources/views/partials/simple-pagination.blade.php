@php
    $pg      = $paginator->currentPage();
    $last    = $paginator->lastPage();
    $pp      = $pageParam ?? 'page';
    $prevUrl = $pg > 1    ? request()->fullUrlWithQuery([$pp => $pg - 1]) : null;
    $nextUrl = $pg < $last ? request()->fullUrlWithQuery([$pp => $pg + 1]) : null;
@endphp
<div style="display:flex;justify-content:flex-end;align-items:center;gap:8px;margin-top:10px;">
    <button class="month-nav"
        @if($pg <= 1) disabled @endif
        @if($prevUrl) onclick="window.location='{{ $prevUrl }}'" @endif
    >Prev</button>
    <div style="font-size:0.95rem;color:#475569;">Page {{ $pg }} of {{ $last }}</div>
    <button class="month-nav"
        @if($pg >= $last) disabled @endif
        @if($nextUrl) onclick="window.location='{{ $nextUrl }}'" @endif
    >Next</button>
</div>
