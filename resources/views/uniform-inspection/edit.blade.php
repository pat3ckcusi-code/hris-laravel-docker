@extends('dashboards.layout', [
    'title'    => 'Edit Inspection #' . $uniformInspection->id,
    'subtitle' => 'Update uniform violation record',
])

@section('page_head')
    @vite(['resources/js/uniform_inspection.js'])
    <style>
        .ui-field-group {
            display: grid;
            grid-template-columns: 160px 140px;
            gap: 16px;
            align-items: start;
        }
        .ui-field { display: flex; flex-direction: column; gap: 4px; }
        .ui-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .ui-label .req { color: #ef4444; margin-left: 2px; }
        .ui-label .opt { font-weight: 400; color: #94a3b8; text-transform: none; letter-spacing: 0; margin-left: 4px; }

        /* Violation row card */
        .vrow-card {
            border: 1px solid #e2e8f0;
            border-left: 4px solid #e2e8f0;
            border-radius: 10px;
            background: #ffffff;
            margin-bottom: 10px;
            transition: border-left-color 150ms;
        }
        .vrow-card.has-employee { border-left-color: #ea580c; }

        .vrow-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 16px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            border-radius: 9px 9px 0 0;
        }
        .vrow-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            background: #ea580c;
            color: #fff;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            margin-right: 8px;
            flex-shrink: 0;
        }
        .vrow-label {
            font-size: 0.78rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .vrow-body { padding: 14px 16px 16px; }

        .vrow-fields {
            display: grid;
            grid-template-columns: 1fr 220px;
            gap: 12px;
            margin-bottom: 12px;
        }

        .emp-search-wrap { position: relative; }
        .emp-search-wrap .emp-search-input { padding-left: 34px; }
        .emp-search-wrap .emp-search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.85rem;
            pointer-events: none;
        }
        .emp-suggestions {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }
        .emp-suggestions .list-group-item {
            border: none;
            border-bottom: 1px solid #f1f5f9;
            padding: 9px 12px;
            font-size: 0.88rem;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .emp-suggestions .list-group-item:last-child { border-bottom: none; }
        .emp-suggestions .list-group-item:hover { background: #f8fafc; }
        .emp-suggestions .list-group-item small { color: #94a3b8; font-size: 0.78rem; }

        .prior-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-top: 5px;
            padding: 3px 8px;
            border-radius: 999px;
            background: #fff7ed;
            color: #9a3412;
            border: 1px solid #fdba74;
        }
        .prior-badge.clean { background: #f0fdf4; color: #166534; border-color: #86efac; }
        .prior-badge.hidden { display: none; }

        #emptyRowsHint {
            border: 2px dashed #e2e8f0;
            border-radius: 10px;
            padding: 28px 20px;
            text-align: center;
            background: #fafafa;
            margin-bottom: 10px;
        }

        @media (max-width: 700px) {
            .ui-field-group { grid-template-columns: 1fr 1fr; }
            .vrow-fields { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .ui-field-group { grid-template-columns: 1fr; }
        }
    </style>
@endsection

@section('top_actions')
    <a href="{{ route('leave-manager.uniform-inspections.show', $uniformInspection) }}"
       class="hris-btn hris-btn-secondary hris-btn-sm">
        <i class="fas fa-arrow-left fa-fw" aria-hidden="true"></i> Back
    </a>
@endsection

@section('content')

@if($errors->any())
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-left:4px solid #ef4444;border-radius:8px;padding:12px 16px;margin-bottom:18px;display:flex;gap:10px;align-items:flex-start;">
        <i class="fas fa-exclamation-circle" style="color:#ef4444;margin-top:2px;flex-shrink:0;"></i>
        <div>
            <strong style="color:#991b1b;font-size:0.88rem;display:block;margin-bottom:4px;">Please fix the following errors:</strong>
            <ul style="margin:0;padding-left:16px;color:#991b1b;font-size:0.85rem;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

<form method="POST"
      action="{{ route('leave-manager.uniform-inspections.update', $uniformInspection) }}"
      id="inspectionForm">
    @csrf
    @method('PUT')

    {{-- ── Section 1: Inspection Details ─────────────────────────── --}}
    <div class="lm-section" style="margin-bottom:16px;">
        <div class="lm-section-header" style="margin-bottom:18px;">
            <h3 style="display:flex;align-items:center;gap:8px;">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;background:#fff7ed;border-radius:6px;border:1px solid #fdba74;">
                    <i class="fas fa-clipboard-list fa-fw" style="color:#ea580c;font-size:0.78rem;" aria-hidden="true"></i>
                </span>
                Inspection Details
            </h3>
            <span style="font-size:0.82rem;color:#64748b;">Inspection #{{ $uniformInspection->id }}</span>
        </div>

        <div class="ui-field-group" style="margin-bottom:16px;">
            <div class="ui-field">
                <label class="ui-label" for="inspection_date">Date <span class="req">*</span></label>
                <input type="date" id="inspection_date" name="inspection_date"
                       class="form-control @error('inspection_date') is-invalid @enderror"
                       value="{{ old('inspection_date', $uniformInspection->inspection_date->format('Y-m-d')) }}" required>
                @error('inspection_date')
                    <span style="color:#ef4444;font-size:0.78rem;">{{ $message }}</span>
                @enderror
            </div>

            <div class="ui-field">
                <label class="ui-label" for="inspection_time">Time <span class="req">*</span></label>
                <input type="time" id="inspection_time" name="inspection_time"
                       class="form-control @error('inspection_time') is-invalid @enderror"
                       value="{{ old('inspection_time', \Carbon\Carbon::createFromFormat('H:i:s', $uniformInspection->inspection_time)->format('H:i')) }}" required>
                @error('inspection_time')
                    <span style="color:#ef4444;font-size:0.78rem;">{{ $message }}</span>
                @enderror
            </div>
        </div>

        <div class="ui-field" style="max-width:680px;">
            <label class="ui-label" for="remarks">
                General Remarks <span class="opt">(optional)</span>
            </label>
            <textarea id="remarks" name="remarks" rows="2"
                      class="form-control @error('remarks') is-invalid @enderror"
                      style="resize:vertical;">{{ old('remarks', $uniformInspection->remarks) }}</textarea>
            @error('remarks')
                <span style="color:#ef4444;font-size:0.78rem;">{{ $message }}</span>
            @enderror
        </div>
    </div>

    {{-- ── Section 2: Violation Rows ───────────────────────────────── --}}
    <div class="lm-section" style="margin-bottom:20px;">
        <div class="lm-section-header" style="margin-bottom:16px;">
            <h3 style="display:flex;align-items:center;gap:8px;">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;background:#fff7ed;border-radius:6px;border:1px solid #fdba74;">
                    <i class="fas fa-users fa-fw" style="color:#ea580c;font-size:0.78rem;" aria-hidden="true"></i>
                </span>
                Employees with Violations
                <span id="rowCountBadge" style="display:none;padding:2px 8px;background:#fee2e2;color:#991b1b;border-radius:999px;font-size:0.72rem;font-weight:700;text-transform:none;letter-spacing:0;"></span>
            </h3>
            <button type="button" id="addRowBtn" class="hris-btn hris-btn-secondary hris-btn-sm">
                <i class="fas fa-plus fa-fw" aria-hidden="true"></i> Add Employee
            </button>
        </div>

        @error('details')
            <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:10px 14px;color:#991b1b;font-size:0.88rem;margin-bottom:12px;">
                <i class="fas fa-exclamation-circle fa-fw"></i> {{ $message }}
            </div>
        @enderror

        <div id="emptyRowsHint" style="display:none;">
            <i class="fas fa-user-slash" style="font-size:1.6rem;color:#cbd5e1;display:block;margin-bottom:10px;" aria-hidden="true"></i>
            <p style="color:#94a3b8;font-size:0.88rem;margin:0 0 14px;">No employees added yet.</p>
            <button type="button" id="addRowBtnAlt" class="hris-btn hris-btn-primary hris-btn-sm">
                <i class="fas fa-plus fa-fw" aria-hidden="true"></i> Add First Employee
            </button>
        </div>

        <div id="violationRowsContainer">
            @foreach($uniformInspection->details as $idx => $detail)
                @php
                    [$offBg, $offColor] = match(true) {
                        $detail->offense_number >= 3 => ['#fee2e2', '#991b1b'],
                        $detail->offense_number === 2 => ['#fff7ed', '#9a3412'],
                        default => ['#f0fdf4', '#166534'],
                    };
                @endphp
                <div class="vrow-card has-employee violation-row" data-index="{{ $idx }}">
                    <input type="hidden" name="details[{{ $idx }}][id]" value="{{ $detail->id }}">

                    <div class="vrow-card-header">
                        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;">
                            <span class="vrow-num row-number">{{ $idx + 1 }}</span>
                            <span class="vrow-label" style="text-transform:none;letter-spacing:0;font-size:0.88rem;color:#1e293b;">
                                {{ $detail->employee?->last_name }}, {{ $detail->employee?->first_name }}
                            </span>
                            <span style="display:inline-flex;align-items:center;padding:2px 8px;background:{{ $offBg }};color:{{ $offColor }};border-radius:999px;font-size:0.72rem;font-weight:700;">
                                Offense #{{ $detail->offense_number }}
                            </span>
                        </div>
                        <button type="button" class="remove-row-btn hris-btn hris-btn-danger hris-btn-sm">
                            <i class="fas fa-times fa-fw" aria-hidden="true"></i> Remove
                        </button>
                    </div>

                    <div class="vrow-body">
                        <div class="vrow-fields">
                            <div class="ui-field emp-search-wrap">
                                <label class="ui-label">Employee <span class="req">*</span></label>
                                <i class="fas fa-search emp-search-icon" aria-hidden="true"></i>
                                <input type="text" class="form-control emp-search-input"
                                       value="{{ $detail->employee?->last_name }}, {{ $detail->employee?->first_name }} ({{ $detail->employee?->EmpNo }})"
                                       placeholder="Type name or EmpNo to search…" autocomplete="off">
                                <input type="hidden" name="details[{{ $idx }}][employee_id]"
                                       class="emp-id-input" value="{{ $detail->employee_id }}">
                                <div class="emp-suggestions list-group"
                                     style="position:absolute;z-index:1050;top:100%;left:0;right:0;display:none;max-height:220px;overflow:auto;"></div>
                                <span class="prior-violations prior-badge hidden"></span>
                            </div>

                            <div class="ui-field">
                                <label class="ui-label">Violation Type <span class="req">*</span></label>
                                <select name="details[{{ $idx }}][violation_type]" class="form-control" required>
                                    <option value="">- Select type -</option>
                                    @foreach($violationTypes as $type)
                                        <option value="{{ $type }}" @selected($detail->violation_type === $type)>{{ $type }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="ui-field">
                            <label class="ui-label">Remarks <span class="opt">(optional)</span></label>
                            <input type="text" name="details[{{ $idx }}][remarks]" class="form-control"
                                   value="{{ old("details.{$idx}.remarks", $detail->remarks) }}"
                                   placeholder="e.g. Wearing slippers, untucked uniform">
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ── Actions ──────────────────────────────────────────────────── --}}
    <div style="display:flex;gap:10px;align-items:center;">
        <button type="submit" class="hris-btn hris-btn-primary">
            <i class="fas fa-save fa-fw" aria-hidden="true"></i> Save Changes
        </button>
        <a href="{{ route('leave-manager.uniform-inspections.show', $uniformInspection) }}"
           class="hris-btn hris-btn-secondary">Cancel</a>
    </div>

</form>

{{-- ── Row template (cloned by JS for new rows) ────────────────────── --}}
<template id="violationRowTemplate">
    <div class="vrow-card" data-index="__INDEX__">

        <div class="vrow-card-header">
            <div style="display:flex;align-items:center;">
                <span class="vrow-num row-number">__NUM__</span>
                <span class="vrow-label">New Employee Violation</span>
            </div>
            <button type="button" class="remove-row-btn hris-btn hris-btn-danger hris-btn-sm">
                <i class="fas fa-times fa-fw" aria-hidden="true"></i> Remove
            </button>
        </div>

        <div class="vrow-body">
            <div class="vrow-fields">
                <div class="ui-field emp-search-wrap">
                    <label class="ui-label">Employee <span class="req" style="color:#ef4444;">*</span></label>
                    <i class="fas fa-search emp-search-icon" aria-hidden="true"></i>
                    <input type="text" class="form-control emp-search-input"
                           placeholder="Type name or employee number…" autocomplete="off">
                    <input type="hidden" name="details[__INDEX__][employee_id]" class="emp-id-input">
                    <div class="emp-suggestions list-group"
                         style="position:absolute;z-index:1050;top:100%;left:0;right:0;display:none;max-height:240px;overflow:auto;"></div>
                    <span class="prior-violations prior-badge hidden"></span>
                </div>

                <div class="ui-field">
                    <label class="ui-label">Violation Type <span class="req" style="color:#ef4444;">*</span></label>
                    <select name="details[__INDEX__][violation_type]" class="form-control" required>
                        <option value="">- Select type -</option>
                        @foreach($violationTypes as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="ui-field">
                <label class="ui-label">Remarks <span style="font-weight:400;color:#94a3b8;text-transform:none;letter-spacing:0;">(optional)</span></label>
                <input type="text" name="details[__INDEX__][remarks]" class="form-control"
                       placeholder="e.g. Wearing slippers, untucked uniform">
            </div>
        </div>
    </div>
</template>

@endsection
