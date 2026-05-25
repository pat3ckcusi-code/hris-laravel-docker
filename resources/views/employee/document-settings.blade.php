@extends('dashboards.layout', [
    'title' => 'Document Settings',
    'subtitle' => 'Manage document types and templates.',
])

@section('content')
    <div class="settings-container">
        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <div class="settings-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <h2>Document Types</h2>
            <a href="{{ route('employee.document-settings.create') }}" class="btn btn-primary">
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
                            <h3>{{ $docType->name }}</h3>
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
                            <a href="{{ route('employee.document-settings.edit', $docType->id) }}" class="btn btn-sm btn-secondary">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <form method="POST" action="{{ route('employee.document-settings.destroy', $docType->id) }}" style="display: inline;" onsubmit="return confirm('Are you sure?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger">
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
