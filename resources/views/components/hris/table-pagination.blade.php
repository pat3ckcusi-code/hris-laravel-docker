@props(['paginator'])

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

            {{-- Pagination Elements --}}
            @foreach($paginator->getUrlRange(1, $paginator->lastPage()) as $page => $url)
                @if($page == $paginator->currentPage())
                    <li class="hris-pagination-item active">
                        <span class="hris-pagination-link">{{ $page }}</span>
                    </li>
                @else
                    <li class="hris-pagination-item">
                        <a class="hris-pagination-link" href="{{ $url }}">{{ $page }}</a>
                    </li>
                @endif
            @endforeach

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
