@extends('dashboards.layout')

@section('content')
    <div class="tile">
        <h2>Locator Print Preview</h2>
        @foreach($locators as $locator)
            <div style="margin-bottom:24px; border:1px solid #e2e8f0; border-radius:8px; padding:16px; background:#fff">
                <div><strong>Type:</strong> {{ $locator->application_type }}</div>
                <div><strong>Location:</strong> {{ $locator->location }}</div>
                <div><strong>Date of Travel:</strong> {{ $locator->travel_date }}</div>
                <div><strong>Intended Departure:</strong> {{ $locator->intended_departure_time }}</div>
                <div><strong>Intended Arrival:</strong> {{ $locator->intended_arrival_time }}</div>
                <div><strong>Detail / Purpose:</strong> {{ $locator->detail }}</div>
                <div><strong>Actual Arrival:</strong> {{ $locator->actual_arrival_time }}</div>
                <div><strong>Status:</strong> {{ ucfirst($locator->status) }}</div>
            </div>
        @endforeach
    </div>
@endsection
