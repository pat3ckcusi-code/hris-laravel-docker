@props(['name' => 'month', 'label' => 'Filter by Month'])

@php
    $currentMonth = request($name) ?? date('m');
    $currentYear = request('year') ?? date('Y');
@endphp

<div class="hris-filter-group">
    <label for="month-filter-{{ $name }}" class="hris-filter-label">{{ $label }}</label>
    <select 
        id="month-filter-{{ $name }}"
        name="{{ $name }}" 
        class="hris-filter-select"
        onchange="document.getElementById('filter-form-{{ $name }}').submit();"
    >
        <option value="">All Months</option>
        @for($i = 1; $i <= 12; $i++)
            <option value="{{ $i }}" {{ $currentMonth == $i ? 'selected' : '' }}>
                {{ \Carbon\Carbon::create()->month($i)->format('F') }}
            </option>
        @endfor
    </select>
</div>

<form id="filter-form-{{ $name }}" method="GET" class="d-none">
    @foreach(request()->query() as $key => $value)
        @if($key !== $name && $key !== 'page')
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endif
    @endforeach
</form>
