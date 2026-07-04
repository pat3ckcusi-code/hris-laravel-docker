@props(['paginator', 'onEachSide' => 2])

@php
    $current = $paginator->currentPage();
    $last = $paginator->lastPage();
    $start = max($current - $onEachSide, 1);
    $end = min($current + $onEachSide, $last);
@endphp

@if($paginator->hasPages())
    <nav class="hris-pagination-wrapper" role="navigation" aria-label="Pagination">
        <ul class="hris-pagination">
            {{-- Previous Page Link --}}
            @if($paginator->onFirstPage())
                <li class="hris-pagination-item disabled">
                    <span class="hris-pagination-link">&laquo;</span>
                </li>
            @else
                <li class="hris-pagination-item">
                    <a class="hris-pagination-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">&laquo;</a>
                </li>
            @endif

            {{-- First page + leading ellipsis --}}
            @if($start > 1)
                <li class="hris-pagination-item">
                    <a class="hris-pagination-link" href="{{ $paginator->url(1) }}">1</a>
                </li>
                @if($start > 2)
                    <li class="hris-pagination-item disabled"><span class="hris-pagination-link">&hellip;</span></li>
                @endif
            @endif

            {{-- Pagination Elements --}}
            @foreach($paginator->getUrlRange($start, $end) as $page => $url)
                @if($page == $current)
                    <li class="hris-pagination-item active">
                        <span class="hris-pagination-link">{{ $page }}</span>
                    </li>
                @else
                    <li class="hris-pagination-item">
                        <a class="hris-pagination-link" href="{{ $url }}">{{ $page }}</a>
                    </li>
                @endif
            @endforeach

            {{-- Trailing ellipsis + last page --}}
            @if($end < $last)
                @if($end < $last - 1)
                    <li class="hris-pagination-item disabled"><span class="hris-pagination-link">&hellip;</span></li>
                @endif
                <li class="hris-pagination-item">
                    <a class="hris-pagination-link" href="{{ $paginator->url($last) }}">{{ $last }}</a>
                </li>
            @endif

            {{-- Next Page Link --}}
            @if($paginator->hasMorePages())
                <li class="hris-pagination-item">
                    <a class="hris-pagination-link" href="{{ $paginator->nextPageUrl() }}" rel="next">&raquo;</a>
                </li>
            @else
                <li class="hris-pagination-item disabled">
                    <span class="hris-pagination-link">&raquo;</span>
                </li>
            @endif
        </ul>
    </nav>

    {{-- Pagination Info --}}
    <div class="hris-pagination-info">
        Showing <strong>{{ $paginator->firstItem() }}</strong> to <strong>{{ $paginator->lastItem() }}</strong> of <strong>{{ $paginator->total() }}</strong> results
    </div>
@endif
