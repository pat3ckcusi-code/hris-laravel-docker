@props([
    'title' => null,
    'subtitle' => null,
    'showExport' => false,
    'showSearch' => true,
    'showMonthFilter' => true,
    'monthFilterName' => 'month',
    'monthFilterDefault' => null,
    'paginator' => null,
    'showTopPagination' => false,
    'scrollableTable' => false,
    'stickyFilters' => false,
])

<div class="hris-table-card">
    {{-- Header: render when title is provided or an $actions slot was passed --}}
    @if($title !== null || isset($actions))
        <div class="hris-table-header">
            <div class="hris-table-header-title">
                @if($title !== null)
                    <h2 class="hris-table-title">{{ $title }}</h2>
                    @if($subtitle)
                        <p class="hris-table-subtitle">{{ $subtitle }}</p>
                    @endif
                @endif
            </div>
            @if($showExport || isset($actions))
                <div class="hris-table-header-actions">
                    @if($showExport)
                        <button type="button" class="hris-btn-secondary" id="export-btn" title="Export data">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="7 10 12 15 17 10"></polyline>
                                <line x1="12" y1="15" x2="12" y2="3"></line>
                            </svg>
                            Export
                        </button>
                    @endif
                    @isset($actions){{ $actions }}@endisset
                </div>
            @endif
        </div>
    @endif

    {{-- Filter bar: use $filters slot if provided, else fall back to default month/search --}}
    @isset($filters)
        <div class="hris-table-filters{{ $stickyFilters ? ' hris-filters-sticky' : '' }}">{{ $filters }}</div>
    @else
        @if($showMonthFilter || $showSearch)
            <div class="hris-table-filters{{ $stickyFilters ? ' hris-filters-sticky' : '' }}">
                @if($showMonthFilter)
                    <div class="hris-filter-left">
                        <x-hris.month-filter :name="$monthFilterName" :default="$monthFilterDefault" />
                    </div>
                @endif
                @if($showSearch)
                    <div class="hris-filter-right">
                        <x-hris.search-bar />
                    </div>
                @endif
            </div>
        @endif
    @endisset

    {{-- Top Pagination --}}
    @if($paginator && $showTopPagination)
        <div class="hris-table-footer hris-table-top-pagination">
            <x-hris.table-pagination :paginator="$paginator" />
        </div>
    @endif

    {{-- Table content --}}
    <div class="hris-table-wrapper overflow-x-auto{{ $scrollableTable ? ' hris-table-scrollable' : '' }}">
        {{ $slot }}
    </div>

    {{-- Bottom Pagination --}}
    @if($paginator)
        <div class="hris-table-footer">
            <x-hris.table-pagination :paginator="$paginator" />
        </div>
    @endif
</div>
