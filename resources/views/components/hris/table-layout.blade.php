@props(['title', 'subtitle' => null, 'showExport' => false, 'showSearch' => true, 'showMonthFilter' => true, 'monthFilterName' => 'month', 'paginator' => null])

<div class="hris-table-card bg-white shadow rounded-lg overflow-hidden">
    {{-- Table Header --}}
    <x-hris.table-header :title="$title" :subtitle="$subtitle" :showExport="$showExport" />

    {{-- Filter Section --}}
    <div class="hris-table-filters">
        @if($showMonthFilter)
            <div class="hris-filter-left">
                <x-hris.month-filter :name="$monthFilterName" />
            </div>
        @endif

        @if($showSearch)
            <div class="hris-filter-right">
                <x-hris.search-bar />
            </div>
        @endif
    </div>

    {{-- Table Content --}}
    <div class="hris-table-wrapper overflow-x-auto">
        {{ $slot }}
    </div>

    {{-- Pagination --}}
    @if($paginator)
        <div class="hris-table-footer">
            <x-hris.table-pagination :paginator="$paginator" />
        </div>
    @endif
</div>
