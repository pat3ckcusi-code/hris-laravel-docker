@extends('dashboards.layout', [
    'title' => 'DTR Excuses',
    'subtitle' => 'Manage DTR excuse records for employees unable to punch due to power interruptions or similar events.',
])

@section('top_actions')
    <button type="button" class="btn btn-sm" onclick="openExcuseModal()">
        <i class="fas fa-plus"></i> Add Excuse
    </button>
@endsection

@section('content')
    @if (session('success'))
        <div class="notice success">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="notice error">
            <ul style="margin:0;padding-left:1.25rem;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="hris-table-card">

        {{-- Filter bar --}}
        <form method="GET" action="{{ route('attendance.dtr-excuse.index') }}">
            <div class="hris-table-filters hris-filters-sticky">
                <div class="hris-filter-left">
                    <div class="hris-filter-group">
                        <label class="hris-filter-label">Employee</label>
                        <input type="text" name="search" class="hris-filter-select"
                               placeholder="Search by name…"
                               value="{{ $filters['search'] }}"
                               style="min-width:180px;">
                    </div>
                    <div class="hris-filter-group">
                        <label class="hris-filter-label">Date From</label>
                        <input type="date" name="date_from" class="hris-filter-select"
                               value="{{ $filters['dateFrom'] }}">
                    </div>
                    <div class="hris-filter-group">
                        <label class="hris-filter-label">Date To</label>
                        <input type="date" name="date_to" class="hris-filter-select"
                               value="{{ $filters['dateTo'] }}">
                    </div>
                    <div class="hris-filter-group">
                        <label class="hris-filter-label">Excuse Type</label>
                        <select name="excuse_type" class="hris-filter-select" style="min-width:200px;">
                            <option value="">All Types</option>
                            <option value="power_interruption"  @selected($filters['excuseType'] === 'power_interruption')>Power Interruption</option>
                            <option value="system_failure"      @selected($filters['excuseType'] === 'system_failure')>System Failure</option>
                            <option value="weather_disturbance" @selected($filters['excuseType'] === 'weather_disturbance')>Force Majeure / Weather</option>
                            <option value="emergency"           @selected($filters['excuseType'] === 'emergency')>Emergency</option>
                            <option value="other"               @selected($filters['excuseType'] === 'other')>Other</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:.5rem;align-items:flex-end;">
                    <button type="submit" class="hris-btn hris-btn-primary hris-btn-sm">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    @if ($filters['search'] || $filters['dateFrom'] || $filters['dateTo'] || $filters['excuseType'])
                        <a href="{{ route('attendance.dtr-excuse.index') }}" class="hris-btn hris-btn-secondary hris-btn-sm">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    @endif
                </div>
            </div>
        </form>

        {{-- Result count --}}
        <div style="padding:.6rem 1.5rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:.8rem;color:#64748b;">
            @if ($excuses->total() > 0)
                Showing <strong>{{ $excuses->firstItem() }}–{{ $excuses->lastItem() }}</strong>
                of <strong>{{ $excuses->total() }}</strong> record{{ $excuses->total() === 1 ? '' : 's' }}
            @else
                No records found
            @endif
        </div>

        <div class="hris-table-wrapper">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Scope</th>
                        <th>Reason</th>
                        <th>Filed By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($excuses as $excuse)
                        @php
                            $typeConfig = \App\Models\DtrExcuse::typeConfig($excuse->excuse_type);
                        @endphp
                        <tr>
                            <td>
                                <span style="font-weight:600;color:#0f172a;">
                                    {{ $excuse->user?->last_name }}, {{ $excuse->user?->first_name }}
                                </span>
                            </td>
                            <td style="white-space:nowrap;">
                                <span style="font-weight:500;">{{ \Carbon\Carbon::parse($excuse->date)->format('M d, Y') }}</span>
                                <br>
                                <span style="font-size:.75rem;color:#94a3b8;">{{ \Carbon\Carbon::parse($excuse->date)->format('l') }}</span>
                            </td>
                            <td style="white-space:nowrap;">
                                <span style="display:inline-flex;align-items:center;gap:.4rem;
                                             background:{{ $typeConfig['bg'] }};color:{{ $typeConfig['color'] }};
                                             padding:.3rem .65rem;border-radius:.375rem;font-size:.78rem;font-weight:600;">
                                    <i class="fas {{ $typeConfig['icon'] }}" style="font-size:.7rem;"></i>
                                    {{ $typeConfig['label'] }}
                                </span>
                            </td>
                            <td>
                                @if ($excuse->is_full_day)
                                    <span class="hris-badge" style="background:#fef9c3;color:#713f12;border:1px solid #fde047;">
                                        <i class="fas fa-sun" style="font-size:.7rem;margin-right:.3rem;"></i>Full Day
                                    </span>
                                @else
                                    <div style="display:flex;flex-wrap:wrap;gap:.3rem;">
                                        @if ($excuse->excuse_am_in)
                                            <span class="hris-badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:.72rem;">AM In</span>
                                        @endif
                                        @if ($excuse->excuse_am_out)
                                            <span class="hris-badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:.72rem;">AM Out</span>
                                        @endif
                                        @if ($excuse->excuse_pm_in)
                                            <span class="hris-badge" style="background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe;font-size:.72rem;">PM In</span>
                                        @endif
                                        @if ($excuse->excuse_pm_out)
                                            <span class="hris-badge" style="background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe;font-size:.72rem;">PM Out</span>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td style="max-width:220px;">
                                @if ($excuse->reason)
                                    <span title="{{ $excuse->reason }}"
                                          style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px;color:#374151;">
                                        {{ $excuse->reason }}
                                    </span>
                                @else
                                    <span style="color:#cbd5e1;">—</span>
                                @endif
                            </td>
                            <td style="white-space:nowrap;">
                                <span style="font-weight:500;">{{ $excuse->filedBy?->last_name }}, {{ $excuse->filedBy?->first_name }}</span>
                                <br>
                                <span style="font-size:.75rem;color:#94a3b8;">{{ $excuse->created_at->format('M d, Y') }}</span>
                            </td>
                            <td>
                                <form method="POST"
                                      action="{{ route('attendance.dtr-excuse.destroy', $excuse) }}"
                                      onsubmit="return confirm('Remove this excuse?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="hris-btn hris-btn-danger hris-btn-sm"
                                            title="Remove excuse"
                                            style="padding:.3rem .55rem;">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="padding:0;border:none;">
                                <div class="hris-empty-state">
                                    <div class="hris-empty-state-icon"><i class="fas fa-file-slash"></i></div>
                                    <div class="hris-empty-state-title">No DTR Excuses Found</div>
                                    <p class="hris-empty-state-text">
                                        @if ($filters['search'] || $filters['dateFrom'] || $filters['dateTo'] || $filters['excuseType'])
                                            No records match your current filters. Try adjusting or
                                            <a href="{{ route('attendance.dtr-excuse.index') }}" style="color:#ea580c;">clearing them</a>.
                                        @else
                                            No excuse records on file. Click <strong>Add Excuse</strong> to file one.
                                        @endif
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($excuses->hasPages())
            <div class="hris-table-footer">
                {{ $excuses->links() }}
            </div>
        @endif

    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         Duplicate Confirmation Overlay
    ════════════════════════════════════════════════════════════════ --}}
    <div id="confirm-overlay"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:10000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:.625rem;overflow:hidden;min-width:380px;max-width:520px;width:95%;box-shadow:0 20px 50px rgba(0,0,0,.25);">
            {{-- Header --}}
            <div style="display:flex;align-items:center;gap:.75rem;padding:1rem 1.25rem;background:#fffbeb;border-bottom:1px solid #fde68a;">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;background:#fef3c7;border-radius:50%;">
                    <i class="fas fa-layer-group" style="color:#b45309;font-size:.85rem;"></i>
                </span>
                <div>
                    <div style="font-weight:700;font-size:.95rem;color:#78350f;">Partial Excuses Already on Record</div>
                    <div style="font-size:.78rem;color:#92400e;margin-top:.1rem;">New slots will be added — no existing slots removed</div>
                </div>
            </div>
            {{-- Body --}}
            <div style="padding:1rem 1.25rem;">
                <ul id="confirm-list"
                    style="margin:0 0 1.25rem;padding:0;list-style:none;font-size:.87rem;color:#374151;max-height:220px;overflow-y:auto;display:flex;flex-direction:column;gap:.5rem;"></ul>
                <div style="display:flex;gap:.75rem;justify-content:flex-end;">
                    <button type="button" class="hris-btn hris-btn-secondary" onclick="closeConfirm()">Cancel</button>
                    <button type="button" class="hris-btn hris-btn-primary" onclick="proceedSubmit()">
                        <i class="fas fa-code-merge"></i> Merge &amp; Submit
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         Add Excuse Modal
    ════════════════════════════════════════════════════════════════ --}}
    <div id="excuse-modal"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:.625rem;overflow:hidden;min-width:480px;max-width:620px;width:95%;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,.25);">

            {{-- Modal header --}}
            <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;background:#fff7ed;border-bottom:1px solid #fed7aa;">
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;background:#ea580c;border-radius:.375rem;">
                        <i class="fas fa-file-medical-alt" style="color:#fff;font-size:.85rem;"></i>
                    </span>
                    <div>
                        <div style="font-weight:700;font-size:.95rem;color:#0f172a;">File DTR Excuse</div>
                        <div style="font-size:.76rem;color:#78350f;">Excuse employees from missed biometric punches</div>
                    </div>
                </div>
                <button type="button" onclick="closeExcuseModal()"
                        style="background:none;border:none;cursor:pointer;font-size:1.25rem;color:#6b7280;line-height:1;padding:.25rem;">
                    &times;
                </button>
            </div>

            {{-- Modal body --}}
            <div style="overflow-y:auto;padding:1.25rem;flex:1;">
                <form id="excuse-form" method="POST" action="{{ route('attendance.dtr-excuse.store') }}">
                    @csrf

                    {{-- Employees --}}
                    <div style="margin-bottom:1.1rem;">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem;">
                            <label style="font-weight:600;font-size:.875rem;color:#0f172a;">
                                <i class="fas fa-users" style="color:#ea580c;margin-right:.3rem;font-size:.8rem;"></i>Employees
                            </label>
                            <span id="emp-selected-count"
                                  style="font-size:.78rem;font-weight:600;color:#ea580c;background:#fff7ed;padding:.15rem .5rem;border-radius:9999px;border:1px solid #fed7aa;">
                                0 selected
                            </span>
                        </div>
                        <input type="text" id="emp-search" placeholder="Search employees…"
                               class="hris-filter-select" style="width:100%;margin-bottom:.4rem;box-sizing:border-box;"
                               oninput="empSearch(this.value)">
                        <div style="border:1px solid #e2e8f0;border-radius:.5rem;overflow:hidden;background:#fafafa;">
                            {{-- Select-all header --}}
                            <div style="display:flex;align-items:center;justify-content:space-between;
                                        padding:.4rem .75rem;background:#f1f5f9;border-bottom:1px solid #e2e8f0;">
                                <label style="display:flex;align-items:center;gap:.45rem;cursor:pointer;font-size:.84rem;font-weight:600;color:#334155;">
                                    <input type="checkbox" id="emp-select-all" onchange="toggleSelectAll(this)">
                                    Select All
                                </label>
                                <span id="emp-page-info" style="font-size:.75rem;color:#94a3b8;"></span>
                            </div>
                            {{-- 2-column wrapping grid --}}
                            <div id="emp-list" style="display:flex;flex-wrap:wrap;padding:.35rem .3rem;min-height:80px;">
                                @foreach ($employees as $emp)
                                    <label class="emp-item"
                                           data-name="{{ strtolower(($emp->last_name ?? '') . ' ' . ($emp->first_name ?? '')) }}"
                                           style="display:flex;align-items:center;gap:.4rem;width:50%;padding:.3rem .45rem;
                                                  cursor:pointer;font-size:.84rem;box-sizing:border-box;border-radius:.25rem;
                                                  transition:background .1s;"
                                           onmouseover="this.style.background='#f0f9ff'"
                                           onmouseout="this.style.background=''">
                                        <input type="checkbox" name="user_ids[]" value="{{ $emp->id }}"
                                               onchange="updateSelectedCount()"
                                               @if(is_array(old('user_ids')) && in_array($emp->id, old('user_ids'))) checked @endif>
                                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#0f172a;">
                                            {{ trim(($emp->last_name ?? '') . ', ' . ($emp->first_name ?? '')) }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            {{-- Pagination controls --}}
                            <div style="display:flex;align-items:center;justify-content:center;gap:.5rem;
                                        padding:.4rem;border-top:1px solid #e2e8f0;background:#f8fafc;">
                                <button type="button" id="emp-prev" onclick="empChangePage(-1)"
                                        style="padding:.2rem .65rem;font-size:.78rem;border:1px solid #cbd5e1;
                                               border-radius:.25rem;background:#fff;cursor:pointer;color:#475569;"
                                        disabled>&lsaquo; Prev</button>
                                <span id="emp-page-label"
                                      style="font-size:.78rem;color:#475569;min-width:90px;text-align:center;"></span>
                                <button type="button" id="emp-next" onclick="empChangePage(1)"
                                        style="padding:.2rem .65rem;font-size:.78rem;border:1px solid #cbd5e1;
                                               border-radius:.25rem;background:#fff;cursor:pointer;color:#475569;">
                                    Next &rsaquo;</button>
                            </div>
                        </div>
                        <p style="margin:.35rem 0 0;font-size:.78rem;color:#94a3b8;">
                            "Select All" applies to the current search result across all pages.
                        </p>
                    </div>

                    {{-- Divider --}}
                    <div style="border-top:1px solid #f1f5f9;margin:.25rem 0 1.1rem;"></div>

                    {{-- Date & Type side by side --}}
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1.1rem;">
                        <div>
                            <label style="display:block;font-weight:600;font-size:.875rem;color:#0f172a;margin-bottom:.35rem;">
                                <i class="fas fa-calendar-day" style="color:#ea580c;margin-right:.3rem;font-size:.8rem;"></i>Date
                            </label>
                            <input type="date" name="date" class="hris-filter-select" style="width:100%;box-sizing:border-box;"
                                   value="{{ old('date') }}" required>
                        </div>
                        <div>
                            <label style="display:block;font-weight:600;font-size:.875rem;color:#0f172a;margin-bottom:.35rem;">
                                <i class="fas fa-tag" style="color:#ea580c;margin-right:.3rem;font-size:.8rem;"></i>Excuse Type
                            </label>
                            <select name="excuse_type" class="hris-filter-select" style="width:100%;box-sizing:border-box;" required>
                                <option value="power_interruption" @selected(old('excuse_type', 'power_interruption') === 'power_interruption')>Power Interruption</option>
                                <option value="system_failure"     @selected(old('excuse_type') === 'system_failure')>System Failure</option>
                                <option value="weather_disturbance" @selected(old('excuse_type') === 'weather_disturbance')>Force Majeure / Weather</option>
                                <option value="emergency"          @selected(old('excuse_type') === 'emergency')>Emergency</option>
                                <option value="other"              @selected(old('excuse_type') === 'other')>Other</option>
                            </select>
                        </div>
                    </div>

                    {{-- Scope --}}
                    <div style="margin-bottom:1.1rem;">
                        <label style="display:block;font-weight:600;font-size:.875rem;color:#0f172a;margin-bottom:.45rem;">
                            <i class="fas fa-clock" style="color:#ea580c;margin-right:.3rem;font-size:.8rem;"></i>Affected Slots
                        </label>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;padding:.75rem;">
                            <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;
                                          font-weight:700;font-size:.88rem;color:#0f172a;margin-bottom:.6rem;">
                                <input type="checkbox" name="is_full_day" value="1"
                                       onchange="toggleSlotCheckboxes(this)"
                                       @checked(old('is_full_day'))>
                                <i class="fas fa-sun" style="color:#d97706;font-size:.8rem;"></i> Full Day
                                <span style="font-weight:400;font-size:.78rem;color:#94a3b8;margin-left:.25rem;">(all slots)</span>
                            </label>
                            <div id="slot-checkboxes" style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem;">
                                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;
                                              font-size:.84rem;background:#eff6ff;border:1px solid #bfdbfe;
                                              border-radius:.375rem;padding:.35rem .6rem;color:#1d4ed8;">
                                    <input type="checkbox" name="excuse_am_in" value="1" @checked(old('excuse_am_in'))>
                                    AM In
                                </label>
                                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;
                                              font-size:.84rem;background:#eff6ff;border:1px solid #bfdbfe;
                                              border-radius:.375rem;padding:.35rem .6rem;color:#1d4ed8;">
                                    <input type="checkbox" name="excuse_am_out" value="1" @checked(old('excuse_am_out'))>
                                    AM Out
                                </label>
                                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;
                                              font-size:.84rem;background:#f5f3ff;border:1px solid #ddd6fe;
                                              border-radius:.375rem;padding:.35rem .6rem;color:#5b21b6;">
                                    <input type="checkbox" name="excuse_pm_in" value="1" @checked(old('excuse_pm_in'))>
                                    PM In
                                </label>
                                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;
                                              font-size:.84rem;background:#f5f3ff;border:1px solid #ddd6fe;
                                              border-radius:.375rem;padding:.35rem .6rem;color:#5b21b6;">
                                    <input type="checkbox" name="excuse_pm_out" value="1" @checked(old('excuse_pm_out'))>
                                    PM Out
                                </label>
                            </div>
                        </div>
                    </div>

                    {{-- Reason --}}
                    <div style="margin-bottom:1.25rem;">
                        <label style="display:block;font-weight:600;font-size:.875rem;color:#0f172a;margin-bottom:.35rem;">
                            <i class="fas fa-comment-alt" style="color:#ea580c;margin-right:.3rem;font-size:.8rem;"></i>Reason
                            <span style="font-weight:400;font-size:.78rem;color:#94a3b8;">(optional)</span>
                        </label>
                        <textarea name="reason" class="hris-filter-select" rows="3"
                                  style="width:100%;resize:vertical;box-sizing:border-box;">{{ old('reason') }}</textarea>
                    </div>

                    {{-- Footer --}}
                    <div style="display:flex;gap:.75rem;justify-content:flex-end;padding-top:.75rem;border-top:1px solid #f1f5f9;">
                        <button type="button" onclick="closeExcuseModal()" class="hris-btn hris-btn-secondary">
                            Cancel
                        </button>
                        <button type="submit" class="hris-btn hris-btn-primary">
                            <i class="fas fa-check"></i> File Excuse
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // ── Modal open/close ─────────────────────────────────────────────
        function openExcuseModal() {
            document.getElementById('excuse-modal').style.display = 'flex';
        }
        function closeExcuseModal() {
            document.getElementById('excuse-modal').style.display = 'none';
        }

        // ── Employee list pagination ─────────────────────────────────────
        var EMP_PAGE_SIZE = 10;
        var empCurrentPage = 1;
        var empFilteredItems = [];

        function empGetAllItems() {
            return Array.from(document.querySelectorAll('#emp-list .emp-item'));
        }

        function empApplyFilter(term) {
            var all = empGetAllItems();
            empFilteredItems = term
                ? all.filter(function(el) { return el.dataset.name.includes(term); })
                : all;
        }

        function empRenderPage() {
            var all = empGetAllItems();
            var pageItems = new Set(
                empFilteredItems.slice((empCurrentPage - 1) * EMP_PAGE_SIZE, empCurrentPage * EMP_PAGE_SIZE)
            );
            all.forEach(function(el) {
                el.style.display = pageItems.has(el) ? '' : 'none';
            });
            var total = empFilteredItems.length;
            var totalPages = Math.max(1, Math.ceil(total / EMP_PAGE_SIZE));
            document.getElementById('emp-page-label').textContent =
                total ? ('Page ' + empCurrentPage + ' of ' + totalPages) : 'No results';
            document.getElementById('emp-prev').disabled = empCurrentPage <= 1;
            document.getElementById('emp-next').disabled = empCurrentPage >= totalPages;
            updateSelectedCount();
        }

        function empSearch(q) {
            empCurrentPage = 1;
            empApplyFilter(q.toLowerCase().trim());
            empRenderPage();
        }

        function empChangePage(delta) {
            var totalPages = Math.max(1, Math.ceil(empFilteredItems.length / EMP_PAGE_SIZE));
            empCurrentPage = Math.min(totalPages, Math.max(1, empCurrentPage + delta));
            empRenderPage();
        }

        function updateSelectedCount() {
            var checked = empGetAllItems().filter(function(el) {
                return el.querySelector('input[type="checkbox"]').checked;
            }).length;
            var badge = document.getElementById('emp-selected-count');
            badge.textContent = checked + ' selected';
            badge.style.background = checked > 0 ? '#fff7ed' : '#f8fafc';
            badge.style.color      = checked > 0 ? '#ea580c' : '#94a3b8';
            badge.style.borderColor= checked > 0 ? '#fed7aa' : '#e2e8f0';

            var filteredChecked = empFilteredItems.filter(function(el) {
                return el.querySelector('input[type="checkbox"]').checked;
            }).length;
            var selectAll = document.getElementById('emp-select-all');
            var total = empFilteredItems.length;
            selectAll.checked       = total > 0 && filteredChecked === total;
            selectAll.indeterminate = filteredChecked > 0 && filteredChecked < total;
        }

        function toggleSelectAll(cb) {
            empFilteredItems.forEach(function(el) {
                el.querySelector('input[type="checkbox"]').checked = cb.checked;
            });
            updateSelectedCount();
        }

        function toggleSlotCheckboxes(cb) {
            var slots = document.getElementById('slot-checkboxes');
            slots.style.opacity      = cb.checked ? '0.4' : '1';
            slots.style.pointerEvents= cb.checked ? 'none' : '';
        }

        // ── Submit interceptor: duplicate check ──────────────────────────
        document.getElementById('excuse-form').addEventListener('submit', function(e) {
            e.preventDefault();

            var userIds = empGetAllItems()
                .filter(function(el) { return el.querySelector('input[type="checkbox"]').checked; })
                .map(function(el)    { return el.querySelector('input[type="checkbox"]').value;   });
            var date = document.querySelector('[name="date"]').value;

            if (!userIds.length || !date) { this.submit(); return; }

            var form  = this;
            var token = document.querySelector('meta[name="csrf-token"]');
            var body  = new URLSearchParams();
            userIds.forEach(function(id) { body.append('user_ids[]', id); });
            body.append('date',   date);
            body.append('_token', token ? token.content : '');

            fetch('{{ route('attendance.dtr-excuse.check') }}', {
                method:  'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body:    body,
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.duplicates && data.duplicates.length) {
                    document.getElementById('confirm-list').innerHTML =
                        data.duplicates.map(function(d) {
                            var slots = d.excused_slots.length ? d.excused_slots.join(', ') : '—';
                            return '<li style="display:flex;align-items:flex-start;gap:.6rem;'
                                 + 'background:#fffbeb;border:1px solid #fde68a;border-radius:.375rem;padding:.55rem .75rem;">'
                                 + '<i class="fas fa-layer-group" style="color:#d97706;margin-top:.2rem;font-size:.8rem;flex-shrink:0;"></i>'
                                 + '<div>'
                                 + '<div style="font-weight:600;font-size:.875rem;">' + d.name + '</div>'
                                 + '<div style="font-size:.78rem;color:#92400e;margin-top:.1rem;">Already excused: <strong>' + slots + '</strong></div>'
                                 + '</div></li>';
                        }).join('');
                    document.getElementById('confirm-overlay').style.display = 'flex';
                    document.getElementById('confirm-overlay')._form = form;
                } else {
                    form.submit();
                }
            })
            .catch(function() { form.submit(); });
        });

        function closeConfirm() {
            document.getElementById('confirm-overlay').style.display = 'none';
        }

        function proceedSubmit() {
            var form = document.getElementById('confirm-overlay')._form;
            closeConfirm();
            if (form) form.submit();
        }

        // ── Init ─────────────────────────────────────────────────────────
        document.addEventListener('DOMContentLoaded', function() {
            empApplyFilter('');
            empRenderPage();
        });
    </script>
@endsection
