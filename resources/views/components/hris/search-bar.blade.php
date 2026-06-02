@props(['placeholder' => 'Search...', 'name' => 'search', 'formId' => 'search-form'])

@php
    $searchValue = request($name) ?? '';
@endphp

<div class="hris-search-wrapper">
    <form id="{{ $formId }}" method="GET" class="hris-search-form">
        <div class="hris-search-input-group">
            <input 
                type="text" 
                name="{{ $name }}" 
                value="{{ $searchValue }}"
                placeholder="{{ $placeholder }}"
                class="hris-search-input"
                aria-label="{{ $placeholder }}"
            >
            <button type="submit" class="hris-search-button" title="Search">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
            </button>
        </div>
        {{-- Preserve other query parameters --}}
        @foreach(request()->query() as $key => $value)
            @if($key !== $name)
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
    </form>
</div>
