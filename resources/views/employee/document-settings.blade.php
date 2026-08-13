@extends('dashboards.layout', [
    'title' => 'Document Settings',
    'subtitle' => 'Manage document types and templates.',
])

@section('page_head')
    @vite(['resources/css/front_desk.css'])
@endsection

@section('content')
    <div class="settings-container">
        @if (session('success'))
            <div class="fd-flash-success">
                <i class="fas fa-circle-check"></i> {{ session('success') }}
            </div>
        @endif

        <div class="settings-header" style="display: flex; justify-content: space-between; align-items: center; margin: 18px 0;">
            <h2 style="display: flex; align-items: center; gap: 10px; margin: 0;"><i class="fas fa-cog" style="color:#ea580c;"></i> Document Types</h2>
            <a href="{{ route('employee.document-settings.create') }}" class="fd-action-btn fd-accept-btn">
                <i class="fas fa-plus"></i> Create Document Type
            </a>
        </div>

        @if($documentTypes->isEmpty())
            <div class="tile">
                <div class="tile-content">
                    <p class="text-muted">No document types created yet. <a href="{{ route('employee.document-settings.create') }}">Create one now</a>.</p>
                </div>
            </div>
        @else
            <div class="document-types-grid">
                @foreach($documentTypes as $docType)
                    <div class="tile document-type-card">
                        <div class="card-header">
                            <h3><i class="fas fa-file-lines" style="color:#ea580c; margin-right: 8px;"></i>{{ $docType->name }}</h3>
                        </div>
                        <div class="card-body">
                            <dl style="font-size: 0.9em;">
                                <dt>Title:</dt>
                                <dd>{{ $docType->parts['title'] ?? 'N/A' }}</dd>
                                <dt>Salutation:</dt>
                                <dd>{{ $docType->parts['salutation'] ?? 'N/A' }}</dd>
                            </dl>
                        </div>
                        <div class="card-footer" style="display: flex; gap: 10px;">
                            <a href="{{ route('employee.document-settings.edit', $docType->id) }}" class="fd-action-btn fd-complete-btn">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <form method="POST" action="{{ route('employee.document-settings.destroy', $docType->id) }}" style="display: inline;" onsubmit="return confirm('Are you sure?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="fd-action-btn fd-reject-btn">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <style>
        .document-types-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .document-type-card {
            display: flex;
            flex-direction: column;
            transition: transform 140ms ease, box-shadow 140ms ease;
        }

        .document-type-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e0e0e0;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.1em;
        }

        .card-body {
            flex: 1;
            margin-bottom: 15px;
        }

        .card-body dl {
            margin: 0;
        }

        .card-body dt {
            font-weight: bold;
            margin-top: 8px;
        }

        .card-body dd {
            margin: 0 0 0 20px;
        }

        .card-footer {
            border-top: 1px solid #e0e0e0;
            padding-top: 10px;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.9em;
        }
    </style>
@endsection
