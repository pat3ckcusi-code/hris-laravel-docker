@extends('dashboards.layout', [
    'title' => 'Document Settings',
    'subtitle' => 'Configure document types and settings.',
])

@section('page_head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@endsection

@section('content')
    <div class="settings-page">
        <section class="tile">
            <div class="tile-header">
                <h2 style="margin: 0;">Document Types</h2>
            </div>
            <div class="tile-content">
                <p>Configure available document types for requests:</p>
                <ul>
                    @forelse($documentTypes as $docType)
                        <li>{{ $docType }}</li>
                    @empty
                        <li>No document types configured.</li>
                    @endforelse
                </ul>
            </div>
        </section>

        <section class="tile">
            <div class="tile-header">
                <h2 style="margin: 0;">General Settings</h2>
            </div>
            <div class="tile-content">
                <form method="POST" action="{{ route('front-desk.index') }}">
                    @csrf
                    <div class="form-group">
                        <label for="max-processing-days">Max Processing Days:</label>
                        <input type="number" id="max-processing-days" name="max_processing_days" min="1" value="5" class="form-input">
                        <small>Number of days allowed to process document requests.</small>
                    </div>
                    <div class="form-group">
                        <label for="enable-notifications">
                            <input type="checkbox" id="enable-notifications" name="enable_notifications" checked>
                            Enable Email Notifications
                        </label>
                        <small>Send email notifications to employees when their requests are processed.</small>
                    </div>
                    <button type="submit" class="btn">Save Settings</button>
                </form>
            </div>
        </section>
    </div>
@endsection
