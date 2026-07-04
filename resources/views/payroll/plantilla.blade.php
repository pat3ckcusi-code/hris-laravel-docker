@extends('dashboards.layout', [
    'title' => 'Plantilla & Salary',
    'subtitle' => 'Manage position titles, salary grades, and assignments.',
])

@section('top_actions')
    <a href="{{ route('payroll.plantilla.service-trail') }}" class="btn btn-sm btn-outline plantilla-nav-btn"><i class="fas fa-route"></i> Service Trail</a>
    <a href="{{ route('payroll.plantilla.reports') }}" class="btn btn-sm btn-outline plantilla-nav-btn"><i class="fas fa-chart-bar"></i> Reports</a>
    <button type="button" class="btn btn-sm btn-add-position" onclick="openCreatePlantilla()"><i class="fas fa-plus"></i> Add Position</button>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <div class="plantilla-stats">
        <div class="stat-tile">
            <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
            <div>
                <div class="stat-value">{{ $stats['total'] }}</div>
                <div class="stat-label">Total Positions</div>
            </div>
        </div>
        <div class="stat-tile stat-filled">
            <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            <div>
                <div class="stat-value">{{ $stats['filled'] }}</div>
                <div class="stat-label">Filled</div>
            </div>
        </div>
        <div class="stat-tile stat-vacant">
            <div class="stat-icon"><i class="fas fa-chair"></i></div>
            <div>
                <div class="stat-value">{{ $stats['vacant'] }}</div>
                <div class="stat-label">Vacant</div>
            </div>
        </div>
    </div>

    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$plantillas">
        <x-slot:filters>
            <form method="GET" action="{{ route('payroll.plantilla.index') }}" class="plantilla-filter-form">
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search item no., title, dept, incumbent..." class="hris-search-input" style="min-width:260px">
                <select name="department" class="hris-filter-select">
                    <option value="">All Departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept }}" @selected(request('department') === $dept)>{{ \Illuminate\Support\Str::limit($dept, 60) }}</option>
                    @endforeach
                </select>
                <select name="status" class="hris-filter-select">
                    <option value="">All Positions</option>
                    <option value="filled" @selected(request('status') === 'filled')>Filled</option>
                    <option value="vacant" @selected(request('status') === 'vacant')>Vacant</option>
                </select>
                <select name="eligibility" class="hris-filter-select">
                    <option value="">All Eligibilities</option>
                    @foreach($eligibilityOptions as $value => $label)
                        <option value="{{ $value }}" @selected(request('eligibility') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm"><i class="fas fa-filter"></i> Filter</button>
                @if(request()->hasAny(['search', 'department', 'status', 'eligibility']))
                    <a href="{{ route('payroll.plantilla.index') }}" class="hris-btn hris-btn-secondary hris-btn-sm">Clear</a>
                @endif
            </form>
        </x-slot:filters>

        <table class="hris-table" id="plantilla-table">
            <colgroup>
                <col style="width:7%">
                <col style="width:17%">
                <col style="width:14%">
                <col style="width:6%">
                <col style="width:11%">
                <col style="width:9%">
                <col style="width:11%">
                <col style="width:15%">
                <col style="width:10%">
            </colgroup>
            <thead>
                <tr>
                    <th>Item No.</th>
                    <th>Title</th>
                    <th>Department</th>
                    <th>SG</th>
                    <th>Step</th>
                    <th>Type</th>
                    <th>CSC Eligibility</th>
                    <th>Incumbent</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($plantillas as $p)
                    @php
                        $incumbent = $p->activeAssignments->first()?->employee;
                        $incumbentLabel = $incumbent ? ($incumbent->last_name ? "{$incumbent->last_name}, {$incumbent->first_name}" : $incumbent->name) : null;
                        $incumbentInitials = $incumbent ? mb_strtoupper(mb_substr($incumbent->first_name ?: $incumbent->name, 0, 1).mb_substr($incumbent->last_name ?: '', 0, 1)) : null;
                    @endphp
                    <tr id="plantilla-row-{{ $p->id }}"
                        data-id="{{ $p->id }}"
                        data-title="{{ $p->title }}"
                        data-item="{{ $p->item_number }}"
                        data-dept="{{ $p->department }}"
                        data-sg="{{ $p->salary_grade }}"
                        data-step="{{ $p->step }}"
                        data-type="{{ $p->employment_type }}"
                        data-eligibility="{{ $p->csc_eligibility }}"
                        data-education="{{ $p->education }}"
                        data-training="{{ $p->training }}"
                        data-experience="{{ $p->experience }}"
                        data-competency="{{ $p->competency }}"
                        data-emp-id="{{ $incumbent?->id }}"
                        data-emp-name="{{ $incumbent ? ($incumbent->last_name ? "{$incumbent->last_name}, {$incumbent->first_name}" : $incumbent->name) : '' }}">
                        <td>@if($p->item_number)<span class="item-badge">{{ $p->item_number }}</span>@else -@endif</td>
                        <td><strong style="font-weight:600">{{ $p->title }}</strong></td>
                        <td class="dept-cell" title="{{ $p->department }}">{{ \Illuminate\Support\Str::limit($p->department, 40) ?: '-' }}</td>
                        <td><span class="sg-badge">SG {{ $p->salary_grade }}</span></td>
                        <td>
                            <span class="step-label">{{ $p->step }}/8</span>
                            <span class="step-dots">
                                @for($i = 1; $i <= 8; $i++)
                                    <span class="dot {{ $i <= $p->step ? 'filled' : '' }}"></span>
                                @endfor
                            </span>
                        </td>
                        <td>{{ ucwords(str_replace('_', ' ', $p->employment_type)) }}</td>
                        <td>
                            @if($p->csc_eligibility)
                                <span class="status-chip eligibility-{{ $p->csc_eligibility }}">{{ $eligibilityOptions[$p->csc_eligibility] }}</span>
                            @else
                                <span class="text-muted">Not specified</span>
                            @endif
                        </td>
                        <td>
                            @if($incumbent)
                                <div class="incumbent-cell">
                                    <span class="avatar-sm">{{ $incumbentInitials ?: '?' }}</span>
                                    <a href="{{ route('payroll.plantilla.service-trail', ['employee_id' => $incumbent->id]) }}" title="View service trail">{{ $incumbentLabel }}</a>
                                </div>
                            @else
                                <span class="status-chip status-vacant">Vacant</span>
                            @endif
                        </td>
                        <td>
                            <div class="action-btns">
                                <a href="{{ route('payroll.plantilla.show', $p->id) }}" class="hris-btn hris-btn-secondary hris-btn-sm" title="View details"><i class="fas fa-eye"></i></a>
                                @if($incumbent)
                                    <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openPromote({{ $p->id }})" title="Promote employee"><i class="fas fa-arrow-trend-up"></i></button>
                                @endif
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openEditPlantilla({{ $p->id }})" title="Edit position"><i class="fas fa-pen"></i></button>
                                <form method="POST" action="{{ route('payroll.plantilla.destroy', $p->id) }}" style="display:inline" id="delete-plantilla-{{ $p->id }}">
                                    @csrf @method('DELETE')
                                    <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="confirmDeletePlantilla({{ $p->id }})" title="Delete position"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9"><p class="empty-state"><i class="fas fa-inbox" style="display:block;font-size:1.5rem;margin-bottom:8px;color:#cbd5e1"></i>No plantilla positions found. Try clearing your filters.</p></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection

@section('modals')
{{-- Promote Employee Modal --}}
<dialog id="promoteModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Promote Employee</h3>
            <span class="record-email" id="promote-subtitle">Move to a higher position</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" action="{{ route('payroll.plantilla.promote') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <input type="hidden" name="employee_id" id="promote-employee-id">
        <div class="form-group">
            <label><i class="fas fa-user"></i> Employee</label>
            <input type="text" id="promote-employee-name" class="form-input" readonly>
        </div>
        <div class="form-group">
            <label for="promote-target"><i class="fas fa-chair"></i> New Position <small>(vacant items only)</small></label>
            <select name="plantilla_id" id="promote-target" class="form-input" style="width:100%;max-width:100%" required onchange="showPromoteTargetInfo(this)">
                <option value="">Select vacant position</option>
                @foreach($vacantPlantillas as $vp)
                    <option value="{{ $vp->id }}"
                        data-item="{{ $vp->item_number }}"
                        data-title="{{ $vp->title }}"
                        data-sg="{{ $vp->salary_grade }}"
                        data-step="{{ $vp->step }}"
                        data-dept="{{ $vp->department }}">
                        {{ $vp->item_number ? "[{$vp->item_number}] " : '' }}{{ \Illuminate\Support\Str::limit($vp->title, 45) }} -SG {{ $vp->salary_grade }}
                    </option>
                @endforeach
            </select>
            <div id="promote-target-info" class="target-info-card">
                <div class="pti-title" id="pti-title"></div>
                <div>Item No. <span id="pti-item"></span> &middot; SG <span id="pti-sg"></span> &middot; Step <span id="pti-step"></span></div>
                <div id="pti-dept" class="text-muted"></div>
            </div>
        </div>
        <div class="form-group">
            <label for="promote-date"><i class="fas fa-calendar-check"></i> Effectivity Date</label>
            <input type="date" name="effective_date" id="promote-date" value="{{ now()->addMonthNoOverflow()->startOfMonth()->toDateString() }}" class="form-input" required>
            <small class="text-muted">Their current assignment ends the day before this date, and their salary grade, step, and designation update automatically. Use the 1st of a month so the payroll period isn't split.</small>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-arrow-trend-up"></i> Promote</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

{{-- Create Plantilla Modal --}}
<dialog id="createPlantillaModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Add Plantilla Position</h3>
            <span class="record-email">Define a new position</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" action="{{ route('payroll.plantilla.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-section-title"><i class="fas fa-id-card"></i>Position Details</div>
        <div class="form-group">
            <label for="c-title">Position Title</label>
            <input type="text" name="title" id="c-title" value="{{ old('title') }}" class="form-input" required>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="c-item">Item Number <small>(optional)</small></label>
                <input type="text" name="item_number" id="c-item" value="{{ old('item_number') }}" class="form-input">
            </div>
            <div class="form-group">
                <label for="c-dept">Department / Office <small>(optional)</small></label>
                <input type="text" name="department" id="c-dept" value="{{ old('department') }}" class="form-input">
            </div>
        </div>
        <div class="form-section-title"><i class="fas fa-sack-dollar"></i>Compensation &amp; Type</div>
        <div class="form-row">
            <div class="form-group">
                <label for="c-sg">Salary Grade (SG)</label>
                <input type="number" name="salary_grade" id="c-sg" min="1" max="33" value="{{ old('salary_grade') }}" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="c-step">Step</label>
                <input type="number" name="step" id="c-step" min="1" max="8" value="{{ old('step', 1) }}" class="form-input" required>
            </div>
        </div>
        <div class="form-group">
            <label for="c-type">Employment Type</label>
            <select name="employment_type" id="c-type" class="form-input" required>
                @foreach(['permanent','casual','co-terminus','contractual','job_order','elected_official'] as $t)
                    <option value="{{ $t }}" @selected(old('employment_type') == $t)>{{ ucwords(str_replace('_', ' ', $t)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label for="c-eligibility">CSC Eligibility Required <small>(optional)</small></label>
            <select name="csc_eligibility" id="c-eligibility" class="form-input">
                <option value="">Not specified</option>
                @foreach($eligibilityOptions as $value => $label)
                    <option value="{{ $value }}" @selected(old('csc_eligibility') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-section-title"><i class="fas fa-graduation-cap"></i>Qualification Standards <small>(optional)</small></div>
        <div class="form-group">
            <label for="c-education">Education</label>
            <textarea name="education" id="c-education" class="form-input" rows="2">{{ old('education') }}</textarea>
        </div>
        <div class="form-group">
            <label for="c-training">Training</label>
            <textarea name="training" id="c-training" class="form-input" rows="2">{{ old('training') }}</textarea>
        </div>
        <div class="form-group">
            <label for="c-experience">Work Experience</label>
            <textarea name="experience" id="c-experience" class="form-input" rows="2">{{ old('experience') }}</textarea>
        </div>
        <div class="form-group">
            <label for="c-competency">Competency</label>
            <textarea name="competency" id="c-competency" class="form-input" rows="2">{{ old('competency') }}</textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-plus"></i> Save Position</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>

{{-- Edit Plantilla Modal --}}
<dialog id="editPlantillaModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">Edit Plantilla Position</h3>
            <span class="record-email" id="edit-plantilla-subtitle">Update position</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" id="editPlantillaForm" class="payroll-form" style="margin-top:12px">
        @csrf @method('PUT')
        <div class="form-section-title"><i class="fas fa-id-card"></i>Position Details</div>
        <div class="form-group">
            <label for="e-title">Position Title</label>
            <input type="text" name="title" id="e-title" class="form-input" required>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="e-item">Item Number <small>(optional)</small></label>
                <input type="text" name="item_number" id="e-item" class="form-input">
            </div>
            <div class="form-group">
                <label for="e-dept">Department / Office <small>(optional)</small></label>
                <input type="text" name="department" id="e-dept" class="form-input">
            </div>
        </div>
        <div class="form-section-title"><i class="fas fa-sack-dollar"></i>Compensation &amp; Type</div>
        <div class="form-row">
            <div class="form-group">
                <label for="e-sg">Salary Grade (SG)</label>
                <input type="number" name="salary_grade" id="e-sg" min="1" max="33" class="form-input" required>
            </div>
            <div class="form-group">
                <label for="e-step">Step</label>
                <input type="number" name="step" id="e-step" min="1" max="8" class="form-input" required>
            </div>
        </div>
        <div class="form-group">
            <label for="e-type">Employment Type</label>
            <select name="employment_type" id="e-type" class="form-input" required>
                @foreach(['permanent','casual','co-terminus','contractual','job_order','elected_official'] as $t)
                    <option value="{{ $t }}">{{ ucwords(str_replace('_', ' ', $t)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group">
            <label for="e-eligibility">CSC Eligibility Required <small>(optional)</small></label>
            <select name="csc_eligibility" id="e-eligibility" class="form-input">
                <option value="">Not specified</option>
                @foreach($eligibilityOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-section-title"><i class="fas fa-graduation-cap"></i>Qualification Standards <small>(optional)</small></div>
        <div class="form-group">
            <label for="e-education">Education</label>
            <textarea name="education" id="e-education" class="form-input" rows="2"></textarea>
        </div>
        <div class="form-group">
            <label for="e-training">Training</label>
            <textarea name="training" id="e-training" class="form-input" rows="2"></textarea>
        </div>
        <div class="form-group">
            <label for="e-experience">Work Experience</label>
            <textarea name="experience" id="e-experience" class="form-input" rows="2"></textarea>
        </div>
        <div class="form-group">
            <label for="e-competency">Competency</label>
            <textarea name="competency" id="e-competency" class="form-input" rows="2"></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn"><i class="fas fa-check"></i> Update</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function openCreatePlantilla() { document.getElementById('createPlantillaModal').showModal(); }

function openPromote(id) {
    var row = document.getElementById('plantilla-row-' + id);
    if (!row || !row.dataset.empId) return;
    document.getElementById('promote-employee-id').value = row.dataset.empId;
    document.getElementById('promote-employee-name').value = row.dataset.empName;
    document.getElementById('promote-subtitle').textContent = 'Currently: ' + row.dataset.title + ' (SG ' + row.dataset.sg + ' Step ' + row.dataset.step + ')';
    var target = document.getElementById('promote-target');
    target.value = '';
    showPromoteTargetInfo(target);
    document.getElementById('promoteModal').showModal();
}

function showPromoteTargetInfo(select) {
    var info = document.getElementById('promote-target-info');
    var opt = select.options[select.selectedIndex];
    if (!opt || !opt.value) { info.style.display = 'none'; return; }
    document.getElementById('pti-title').textContent = opt.dataset.title;
    document.getElementById('pti-item').textContent = opt.dataset.item || '-';
    document.getElementById('pti-sg').textContent = opt.dataset.sg;
    document.getElementById('pti-step').textContent = opt.dataset.step;
    document.getElementById('pti-dept').textContent = opt.dataset.dept || '';
    info.style.display = 'block';
}

function openEditPlantilla(id) {
    var row = document.getElementById('plantilla-row-' + id);
    if (!row) return;
    document.getElementById('editPlantillaForm').action = '{{ url("payroll-manager/plantilla") }}/' + id;
    document.getElementById('e-title').value = row.dataset.title;
    document.getElementById('e-item').value = row.dataset.item;
    document.getElementById('e-dept').value = row.dataset.dept;
    document.getElementById('e-sg').value = row.dataset.sg;
    document.getElementById('e-step').value = row.dataset.step;
    document.getElementById('e-type').value = row.dataset.type;
    document.getElementById('e-eligibility').value = row.dataset.eligibility || '';
    document.getElementById('e-education').value = row.dataset.education || '';
    document.getElementById('e-training').value = row.dataset.training || '';
    document.getElementById('e-experience').value = row.dataset.experience || '';
    document.getElementById('e-competency').value = row.dataset.competency || '';
    document.getElementById('edit-plantilla-subtitle').textContent = row.dataset.title;
    document.getElementById('editPlantillaModal').showModal();
}

function confirmDeletePlantilla(id) {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: 'Delete this position?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' })
            .then(function(r) { if (r.isConfirmed) document.getElementById('delete-plantilla-' + id).submit(); });
    } else if (confirm('Delete?')) {
        document.getElementById('delete-plantilla-' + id).submit();
    }
}

@if ($errors->any())
    document.getElementById('createPlantillaModal').showModal();
@endif
</script>
@endsection
