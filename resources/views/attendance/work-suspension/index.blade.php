@extends('dashboards.layout', [
    'title' => 'Work Suspensions',
    'subtitle' => 'Declare a company-wide work suspension (weather, urgent event) and its effect on DTR penalties.',
])

@section('top_actions')
    <button type="button" class="btn btn-sm" onclick="openSuspensionModal()">
        <i class="fas fa-plus"></i> Declare Suspension
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

    <div style="display:flex;align-items:flex-start;gap:.6rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:.5rem;padding:.75rem 1rem;margin-bottom:1.25rem;">
        <i class="fas fa-shield-heart" style="color:#2563eb;font-size:.9rem;margin-top:.15rem;"></i>
        <span style="font-size:.85rem;color:#1e40af;line-height:1.5;">
            Frontline/essential personnel (health, disaster response, security) are automatically exempt from every
            suspension declared here and must keep reporting normally. Manage who's exempt on the
            <a href="{{ route('attendance.frontline-personnel.index') }}" style="color:#1d4ed8;font-weight:600;">Frontline Personnel</a> screen.
        </span>
    </div>

    <div class="hris-table-card">

        {{-- Filter bar --}}
        <form method="GET" action="{{ route('attendance.work-suspensions.index') }}">
            <div class="hris-table-filters hris-filters-sticky">
                <div class="hris-filter-left">
                    <div class="hris-filter-group">
                        <label class="hris-filter-label">Reason</label>
                        <input type="text" name="search" class="hris-filter-select"
                               placeholder="Search reason…"
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
                        <label class="hris-filter-label">Type</label>
                        <select name="type" class="hris-filter-select" style="min-width:180px;">
                            <option value="">All Types</option>
                            <option value="weather" @selected($filters['type'] === 'weather')>Weather / Typhoon</option>
                            <option value="event"   @selected($filters['type'] === 'event')>Urgent Event</option>
                            <option value="other"   @selected($filters['type'] === 'other')>Other</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:.5rem;align-items:flex-end;">
                    <button type="submit" class="hris-btn hris-btn-primary hris-btn-sm">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    @if ($filters['search'] || $filters['dateFrom'] || $filters['dateTo'] || $filters['type'])
                        <a href="{{ route('attendance.work-suspensions.index') }}" class="hris-btn hris-btn-secondary hris-btn-sm">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    @endif
                </div>
            </div>
        </form>

        {{-- Result count --}}
        <div style="padding:.6rem 1.5rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:.8rem;color:#64748b;">
            @if ($suspensions->total() > 0)
                Showing <strong>{{ $suspensions->firstItem() }}–{{ $suspensions->lastItem() }}</strong>
                of <strong>{{ $suspensions->total() }}</strong> record{{ $suspensions->total() === 1 ? '' : 's' }}
            @else
                No records found
            @endif
        </div>

        <div class="hris-table-wrapper">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Effect</th>
                        <th>Reason</th>
                        <th>Declared By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($suspensions as $suspension)
                        @php
                            $typeConfig = \App\Models\WorkSuspension::typeConfig($suspension->type);
                            $timeHm = $suspension->suspension_time ? substr($suspension->suspension_time, 0, 5) : null;
                            $editPayload = [
                                'id' => $suspension->id,
                                'suspension_date' => $suspension->suspension_date->format('Y-m-d'),
                                'suspension_time' => $timeHm,
                                'type' => $suspension->type,
                                'reason' => $suspension->reason,
                            ];
                        @endphp
                        <tr>
                            <td style="white-space:nowrap;">
                                <span style="font-weight:500;">{{ $suspension->suspension_date->format('M d, Y') }}</span>
                                <br>
                                <span style="font-size:.75rem;color:#94a3b8;">{{ $suspension->suspension_date->format('l') }}</span>
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
                                @if ($timeHm)
                                    <span class="hris-badge" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;">
                                        <i class="fas fa-clock" style="font-size:.7rem;margin-right:.3rem;"></i>From {{ \Carbon\Carbon::parse($timeHm)->format('g:i A') }}
                                    </span>
                                @else
                                    <span class="hris-badge" style="background:#fef9c3;color:#713f12;border:1px solid #fde047;">
                                        <i class="fas fa-sun" style="font-size:.7rem;margin-right:.3rem;"></i>Full Day
                                    </span>
                                @endif
                            </td>
                            <td style="max-width:220px;">
                                <span title="{{ $suspension->reason }}"
                                      style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px;color:#374151;">
                                    {{ $suspension->reason }}
                                </span>
                            </td>
                            <td style="white-space:nowrap;">
                                <span style="font-weight:500;">{{ $suspension->creator?->last_name }}, {{ $suspension->creator?->first_name }}</span>
                                <br>
                                <span style="font-size:.75rem;color:#94a3b8;">{{ $suspension->created_at->format('M d, Y') }}</span>
                            </td>
                            <td style="white-space:nowrap;">
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm"
                                        title="Edit"
                                        style="padding:.3rem .55rem;"
                                        onclick='openEditModal(@json($editPayload))'>
                                    <i class="fas fa-pen"></i>
                                </button>
                                <form id="delete-form-{{ $suspension->id }}" method="POST"
                                      action="{{ route('attendance.work-suspensions.destroy', $suspension) }}"
                                      style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="button" class="hris-btn hris-btn-danger hris-btn-sm"
                                            title="Remove"
                                            style="padding:.3rem .55rem;"
                                            onclick='openDeleteConfirm(@json($editPayload), "delete-form-{{ $suspension->id }}")'>
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="padding:0;border:none;">
                                <div class="hris-empty-state">
                                    <div class="hris-empty-state-icon"><i class="fas fa-file-slash"></i></div>
                                    <div class="hris-empty-state-title">No Work Suspensions Found</div>
                                    <p class="hris-empty-state-text">
                                        @if ($filters['search'] || $filters['dateFrom'] || $filters['dateTo'] || $filters['type'])
                                            No records match your current filters. Try adjusting or
                                            <a href="{{ route('attendance.work-suspensions.index') }}" style="color:#ea580c;">clearing them</a>.
                                        @else
                                            No work suspensions on file. Click <strong>Declare Suspension</strong> to add one.
                                        @endif
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($suspensions->hasPages())
            <div class="hris-table-footer">
                {{ $suspensions->links() }}
            </div>
        @endif

    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         Confirmation Overlay
    ════════════════════════════════════════════════════════════════ --}}
    <div id="suspension-confirm-overlay"
         style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:.75rem;overflow:hidden;min-width:380px;max-width:460px;width:95%;box-shadow:0 25px 60px rgba(0,0,0,.3);">
            {{-- Header --}}
            <div style="display:flex;align-items:center;gap:.75rem;padding:1.1rem 1.25rem;background:linear-gradient(135deg,#ea580c,#c2410c);">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:2.4rem;height:2.4rem;background:rgba(255,255,255,.18);border-radius:50%;flex-shrink:0;">
                    <i class="fas fa-triangle-exclamation" style="color:#fff;font-size:1rem;"></i>
                </span>
                <div>
                    <div id="suspension-confirm-title" style="font-weight:700;font-size:1rem;color:#fff;">Confirm Work Suspension</div>
                    <div style="font-size:.78rem;color:#ffedd5;margin-top:.1rem;">Please review before saving</div>
                </div>
            </div>

            {{-- Body --}}
            <div style="padding:1.1rem 1.25rem;">
                <div style="display:flex;flex-direction:column;gap:.65rem;margin-bottom:1rem;">
                    <div style="display:flex;align-items:center;gap:.7rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;padding:.6rem .8rem;">
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:1.9rem;height:1.9rem;background:#fff7ed;border-radius:.4rem;flex-shrink:0;">
                            <i class="fas fa-calendar-day" style="color:#ea580c;font-size:.8rem;"></i>
                        </span>
                        <div style="min-width:0;">
                            <div style="font-size:.7rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;">Date</div>
                            <div id="suspension-confirm-date" style="font-size:.87rem;font-weight:600;color:#0f172a;"></div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:.7rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;padding:.6rem .8rem;">
                        <span id="suspension-confirm-coverage-icon-wrap" style="display:inline-flex;align-items:center;justify-content:center;width:1.9rem;height:1.9rem;background:#fff7ed;border-radius:.4rem;flex-shrink:0;">
                            <i id="suspension-confirm-coverage-icon" class="fas fa-clock" style="color:#ea580c;font-size:.8rem;"></i>
                        </span>
                        <div style="min-width:0;">
                            <div style="font-size:.7rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;">Coverage</div>
                            <div id="suspension-confirm-coverage" style="font-size:.87rem;font-weight:600;color:#0f172a;"></div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:.7rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;padding:.6rem .8rem;">
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:1.9rem;height:1.9rem;background:#fff7ed;border-radius:.4rem;flex-shrink:0;margin-top:.05rem;">
                            <i class="fas fa-comment-alt" style="color:#ea580c;font-size:.8rem;"></i>
                        </span>
                        <div style="min-width:0;">
                            <div style="font-size:.7rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;">Reason</div>
                            <div id="suspension-confirm-reason" style="font-size:.87rem;font-weight:600;color:#0f172a;word-break:break-word;"></div>
                        </div>
                    </div>
                </div>

                <div style="display:flex;align-items:flex-start;gap:.55rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:.5rem;padding:.65rem .8rem;margin-bottom:1.2rem;">
                    <i class="fas fa-circle-info" style="color:#2563eb;font-size:.85rem;margin-top:.15rem;flex-shrink:0;"></i>
                    <span style="font-size:.78rem;color:#1e40af;line-height:1.4;">
                        Affected employees' DTRs will be recomputed so late/undertime is not charged during the suspended period.
                    </span>
                </div>

                <div style="display:flex;gap:.75rem;justify-content:flex-end;">
                    <button type="button" class="hris-btn hris-btn-secondary" onclick="closeSuspensionConfirm()">
                        <i class="fas fa-arrow-left"></i> Go Back
                    </button>
                    <button type="button" id="suspension-confirm-btn" class="hris-btn hris-btn-primary">
                        <i class="fas fa-check"></i> Confirm &amp; Save
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         Delete Confirmation Overlay
    ════════════════════════════════════════════════════════════════ --}}
    <div id="suspension-delete-overlay"
         style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:.75rem;overflow:hidden;min-width:380px;max-width:440px;width:95%;box-shadow:0 25px 60px rgba(0,0,0,.3);">
            {{-- Header --}}
            <div style="display:flex;align-items:center;gap:.75rem;padding:1.1rem 1.25rem;background:linear-gradient(135deg,#dc2626,#991b1b);">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:2.4rem;height:2.4rem;background:rgba(255,255,255,.18);border-radius:50%;flex-shrink:0;">
                    <i class="fas fa-trash-alt" style="color:#fff;font-size:1rem;"></i>
                </span>
                <div>
                    <div style="font-weight:700;font-size:1rem;color:#fff;">Remove Work Suspension?</div>
                    <div style="font-size:.78rem;color:#fecaca;margin-top:.1rem;">This action cannot be undone</div>
                </div>
            </div>

            {{-- Body --}}
            <div style="padding:1.1rem 1.25rem;">
                <div style="display:flex;flex-direction:column;gap:.65rem;margin-bottom:1rem;">
                    <div style="display:flex;align-items:center;gap:.7rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;padding:.6rem .8rem;">
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:1.9rem;height:1.9rem;background:#fef2f2;border-radius:.4rem;flex-shrink:0;">
                            <i class="fas fa-calendar-day" style="color:#dc2626;font-size:.8rem;"></i>
                        </span>
                        <div style="min-width:0;">
                            <div style="font-size:.7rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;">Date</div>
                            <div id="suspension-delete-date" style="font-size:.87rem;font-weight:600;color:#0f172a;"></div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:.7rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:.5rem;padding:.6rem .8rem;">
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:1.9rem;height:1.9rem;background:#fef2f2;border-radius:.4rem;flex-shrink:0;margin-top:.05rem;">
                            <i class="fas fa-comment-alt" style="color:#dc2626;font-size:.8rem;"></i>
                        </span>
                        <div style="min-width:0;">
                            <div style="font-size:.7rem;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.03em;">Reason</div>
                            <div id="suspension-delete-reason" style="font-size:.87rem;font-weight:600;color:#0f172a;word-break:break-word;"></div>
                        </div>
                    </div>
                </div>

                <div style="display:flex;align-items:flex-start;gap:.55rem;background:#fffbeb;border:1px solid #fde68a;border-radius:.5rem;padding:.65rem .8rem;margin-bottom:1.2rem;">
                    <i class="fas fa-triangle-exclamation" style="color:#d97706;font-size:.85rem;margin-top:.15rem;flex-shrink:0;"></i>
                    <span style="font-size:.78rem;color:#92400e;line-height:1.4;">
                        Affected employees' DTRs will be recomputed - anyone who benefited from this suspension may
                        show late/undertime again once it's removed.
                    </span>
                </div>

                <div style="display:flex;gap:.75rem;justify-content:flex-end;">
                    <button type="button" class="hris-btn hris-btn-secondary" onclick="closeDeleteConfirm()">
                        <i class="fas fa-arrow-left"></i> Cancel
                    </button>
                    <button type="button" id="suspension-delete-btn" class="hris-btn hris-btn-danger">
                        <i class="fas fa-trash-alt"></i> Confirm &amp; Remove
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         Declare / Edit Suspension Modal
    ════════════════════════════════════════════════════════════════ --}}
    <div id="suspension-modal"
         style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:.625rem;overflow:hidden;min-width:420px;max-width:520px;width:95%;max-height:92vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,.25);">

            {{-- Modal header --}}
            <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;background:#fff7ed;border-bottom:1px solid #fed7aa;">
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <span style="display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;background:#ea580c;border-radius:.375rem;">
                        <i class="fas fa-triangle-exclamation" style="color:#fff;font-size:.85rem;"></i>
                    </span>
                    <div>
                        <div id="suspension-modal-title" style="font-weight:700;font-size:.95rem;color:#0f172a;">Declare Work Suspension</div>
                        <div style="font-size:.76rem;color:#78350f;">Applies company-wide - no late/undertime past the cutoff</div>
                    </div>
                </div>
                <button type="button" onclick="closeSuspensionModal()"
                        style="background:none;border:none;cursor:pointer;font-size:1.25rem;color:#6b7280;line-height:1;padding:.25rem;">
                    &times;
                </button>
            </div>

            {{-- Modal body --}}
            <div style="overflow-y:auto;padding:1.25rem;flex:1;">
                <form id="suspension-form" method="POST" action="{{ route('attendance.work-suspensions.store') }}">
                    @csrf
                    <div id="suspension-method-field"></div>

                    {{-- Date --}}
                    <div style="margin-bottom:1.1rem;">
                        <label style="display:block;font-weight:600;font-size:.875rem;color:#0f172a;margin-bottom:.35rem;">
                            <i class="fas fa-calendar-day" style="color:#ea580c;margin-right:.3rem;font-size:.8rem;"></i>Date
                        </label>
                        <input type="date" name="suspension_date" id="suspension-date" class="hris-filter-select"
                               style="width:100%;box-sizing:border-box;" value="{{ old('suspension_date') }}" required>
                    </div>

                    {{-- Full day toggle + cutoff time --}}
                    <div style="margin-bottom:1.1rem;">
                        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;
                                      font-weight:700;font-size:.88rem;color:#0f172a;margin-bottom:.6rem;">
                            <input type="checkbox" id="suspension-full-day" onchange="toggleSuspensionTime(this)"
                                   @checked(old('suspension_time') === null)>
                            <i class="fas fa-sun" style="color:#d97706;font-size:.8rem;"></i> Full Day
                            <span style="font-weight:400;font-size:.78rem;color:#94a3b8;margin-left:.25rem;">(no work at all)</span>
                        </label>
                        <div id="suspension-time-wrap">
                            <label style="display:block;font-weight:600;font-size:.875rem;color:#0f172a;margin-bottom:.35rem;">
                                <i class="fas fa-clock" style="color:#ea580c;margin-right:.3rem;font-size:.8rem;"></i>Suspended From
                            </label>
                            <input type="time" name="suspension_time" id="suspension-time" class="hris-filter-select"
                                   style="width:100%;box-sizing:border-box;" value="{{ old('suspension_time') }}">
                            <p style="margin:.35rem 0 0;font-size:.78rem;color:#94a3b8;">
                                Employees who leave at or after this time are not charged undertime.
                            </p>
                        </div>
                    </div>

                    {{-- Type --}}
                    <div style="margin-bottom:1.1rem;">
                        <label style="display:block;font-weight:600;font-size:.875rem;color:#0f172a;margin-bottom:.35rem;">
                            <i class="fas fa-tag" style="color:#ea580c;margin-right:.3rem;font-size:.8rem;"></i>Type
                        </label>
                        <select name="type" id="suspension-type" class="hris-filter-select" style="width:100%;box-sizing:border-box;" required>
                            <option value="weather" @selected(old('type', 'weather') === 'weather')>Weather / Typhoon</option>
                            <option value="event"   @selected(old('type') === 'event')>Urgent Event</option>
                            <option value="other"   @selected(old('type') === 'other')>Other</option>
                        </select>
                    </div>

                    {{-- Reason --}}
                    <div style="margin-bottom:1.25rem;">
                        <label style="display:block;font-weight:600;font-size:.875rem;color:#0f172a;margin-bottom:.35rem;">
                            <i class="fas fa-comment-alt" style="color:#ea580c;margin-right:.3rem;font-size:.8rem;"></i>Reason
                        </label>
                        <textarea name="reason" id="suspension-reason" class="hris-filter-select" rows="3"
                                  style="width:100%;resize:vertical;box-sizing:border-box;" required>{{ old('reason') }}</textarea>
                    </div>

                    {{-- Footer --}}
                    <div style="display:flex;gap:.75rem;justify-content:flex-end;padding-top:.75rem;border-top:1px solid #f1f5f9;">
                        <button type="button" onclick="closeSuspensionModal()" class="hris-btn hris-btn-secondary">
                            Cancel
                        </button>
                        <button type="submit" class="hris-btn hris-btn-primary">
                            <i class="fas fa-check"></i> Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        var STORE_URL = @json(route('attendance.work-suspensions.store'));

        function updateUrlFor(id) {
            return STORE_URL.replace(/\/work-suspensions$/, '/work-suspensions/' + id);
        }

        function openSuspensionModal() {
            document.getElementById('suspension-form').reset();
            document.getElementById('suspension-form').action = STORE_URL;
            document.getElementById('suspension-method-field').innerHTML = '';
            document.getElementById('suspension-modal-title').textContent = 'Declare Work Suspension';
            document.getElementById('suspension-full-day').checked = false;
            toggleSuspensionTime(document.getElementById('suspension-full-day'));
            document.getElementById('suspension-modal').style.display = 'flex';
        }

        function openEditModal(suspension) {
            document.getElementById('suspension-form').reset();
            document.getElementById('suspension-form').action = updateUrlFor(suspension.id);
            document.getElementById('suspension-method-field').innerHTML = '@method('PUT')';
            document.getElementById('suspension-modal-title').textContent = 'Edit Work Suspension';
            document.getElementById('suspension-date').value = suspension.suspension_date;
            document.getElementById('suspension-type').value = suspension.type;
            document.getElementById('suspension-reason').value = suspension.reason;
            var fullDay = document.getElementById('suspension-full-day');
            fullDay.checked = !suspension.suspension_time;
            toggleSuspensionTime(fullDay);
            if (suspension.suspension_time) {
                document.getElementById('suspension-time').value = suspension.suspension_time;
            }
            document.getElementById('suspension-modal').style.display = 'flex';
        }

        function closeSuspensionModal() {
            document.getElementById('suspension-modal').style.display = 'none';
        }

        function toggleSuspensionTime(cb) {
            var wrap = document.getElementById('suspension-time-wrap');
            var input = document.getElementById('suspension-time');
            wrap.style.display = cb.checked ? 'none' : '';
            if (cb.checked) {
                input.value = '';
            }
        }

        // ── Submit interceptor: confirm before declaring/updating ─────────
        function formatConfirmDate(value) {
            if (!value) return '(no date set)';
            var d = new Date(value + 'T00:00:00');
            if (isNaN(d)) return value;
            return d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        }

        function formatConfirmTime(value) {
            if (!value) return null;
            var parts = value.split(':');
            var d = new Date(1970, 0, 1, parseInt(parts[0], 10), parseInt(parts[1], 10));
            return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        }

        document.getElementById('suspension-form').addEventListener('submit', function(e) {
            e.preventDefault();

            var isEdit = document.getElementById('suspension-method-field').innerHTML !== '';
            var date = document.getElementById('suspension-date').value;
            var fullDay = document.getElementById('suspension-full-day').checked;
            var time = document.getElementById('suspension-time').value;
            var reason = document.getElementById('suspension-reason').value.trim();
            var formattedTime = formatConfirmTime(time);

            document.getElementById('suspension-confirm-title').textContent =
                isEdit ? 'Confirm Suspension Update' : 'Confirm Work Suspension';
            document.getElementById('suspension-confirm-date').textContent = formatConfirmDate(date);
            document.getElementById('suspension-confirm-coverage').textContent = fullDay
                ? 'Full day - no work at all'
                : ('From ' + (formattedTime || '(no time set)') + ' onward');
            document.getElementById('suspension-confirm-coverage-icon').className =
                fullDay ? 'fas fa-sun' : 'fas fa-clock';
            document.getElementById('suspension-confirm-reason').textContent = reason || '(none provided)';

            var form = this;
            var confirmBtn = document.getElementById('suspension-confirm-btn');
            confirmBtn.innerHTML = '<i class="fas fa-check"></i> ' + (isEdit ? 'Confirm & Update' : 'Confirm & Save');
            confirmBtn.onclick = function() {
                closeSuspensionConfirm();
                form.submit();
            };

            document.getElementById('suspension-confirm-overlay').style.display = 'flex';
        });

        function closeSuspensionConfirm() {
            document.getElementById('suspension-confirm-overlay').style.display = 'none';
        }

        // ── Delete confirmation overlay ────────────────────────────────────
        function openDeleteConfirm(suspension, formId) {
            document.getElementById('suspension-delete-date').textContent = formatConfirmDate(suspension.suspension_date);
            document.getElementById('suspension-delete-reason').textContent = suspension.reason || '(none provided)';

            var form = document.getElementById(formId);
            var deleteBtn = document.getElementById('suspension-delete-btn');
            deleteBtn.onclick = function() {
                closeDeleteConfirm();
                form.submit();
            };

            document.getElementById('suspension-delete-overlay').style.display = 'flex';
        }

        function closeDeleteConfirm() {
            document.getElementById('suspension-delete-overlay').style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            toggleSuspensionTime(document.getElementById('suspension-full-day'));
            @if ($errors->any())
                openSuspensionModal();
            @endif
        });
    </script>
@endsection
