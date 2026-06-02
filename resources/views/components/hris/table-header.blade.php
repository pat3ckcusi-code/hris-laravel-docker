@props(['title', 'subtitle' => null, 'showExport' => false])

<div class="hris-table-header">
    <div class="hris-table-header-title">
        <h2 class="hris-table-title">{{ $title }}</h2>
        @if($subtitle)
            <p class="hris-table-subtitle">{{ $subtitle }}</p>
        @endif
    </div>
    @if($showExport)
        <div class="hris-table-header-actions">
            <button type="button" class="hris-btn-secondary" id="export-btn" title="Export data">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Export
            </button>
        </div>
    @endif
</div>
