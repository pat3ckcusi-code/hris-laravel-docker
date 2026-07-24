@extends('dashboards.layout', [
    'title' => 'Plantilla Reports',
    'subtitle' => 'Vacancies, promotions, and assignment activity for management review.',
])

@section('top_actions')
    <a href="{{ route("{$routePrefix}.plantilla.service-trail") }}" class="btn btn-sm btn-outline plantilla-nav-btn"><i class="fas fa-route"></i> Service Trail</a>
    <a href="{{ route("{$routePrefix}.plantilla.index") }}" class="btn btn-sm btn-outline">Back to Plantilla</a>
@endsection

@section('content')
    @php
        $employeeName = function ($id) use ($employees) {
            $u = $employees->get($id);
            if (! $u) return 'Employee #'.$id;
            return $u->last_name ? "{$u->last_name}, {$u->first_name}" : $u->name;
        };
    @endphp

    {{-- Summary --}}
    <div class="plantilla-stats">
        <div class="stat-tile">
            <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
            <div>
                <div class="stat-value">{{ $stats['total'] }}</div>
                <div class="stat-label">Plantilla Items</div>
            </div>
        </div>
        <div class="stat-tile stat-filled">
            <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            <div>
                <div class="stat-value">{{ $stats['filled'] }}</div>
                <div class="stat-label">Filled Positions</div>
            </div>
        </div>
        <div class="stat-tile stat-vacant">
            <div class="stat-icon"><i class="fas fa-chair"></i></div>
            <div>
                <div class="stat-value">{{ $stats['vacant'] }}</div>
                <div class="stat-label">Vacant Positions</div>
            </div>
        </div>
        <div class="stat-tile stat-promo">
            <div class="stat-icon"><i class="fas fa-arrow-trend-up"></i></div>
            <div>
                <div class="stat-value">{{ $stats['promotions_this_year'] }}</div>
                <div class="stat-label">Promotions in {{ now()->year }}</div>
            </div>
        </div>
    </div>

    {{-- Promotions --}}
    <section class="payroll-section">
        <h2><i class="fas fa-arrow-trend-up"></i>Promotion History</h2>
        <form method="GET" action="{{ route("{$routePrefix}.plantilla.reports") }}" class="plantilla-filter-form" style="margin-bottom:14px">
            <input type="text" name="promotion_search" value="{{ request('promotion_search') }}" placeholder="Search employee name or EmpNo..." class="hris-search-input" style="min-width:260px">
            <input type="hidden" name="vacant_search" value="{{ request('vacant_search') }}">
            <input type="hidden" name="vacant_department" value="{{ request('vacant_department') }}">
            <input type="hidden" name="activity_search" value="{{ request('activity_search') }}">
            <input type="hidden" name="activity_action" value="{{ request('activity_action') }}">
            <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm"><i class="fas fa-filter"></i> Filter</button>
            @if(request('promotion_search'))
                <a href="{{ route("{$routePrefix}.plantilla.reports", request()->except('promotion_search', 'promotions_page')) }}" class="hris-btn hris-btn-secondary hris-btn-sm">Clear</a>
            @endif
        </form>
        @if($promotions->count())
            <div class="plantilla-panel overflow-x-auto">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <th>Logged</th>
                            <th>Employee</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Effective</th>
                            <th>Original Appointment</th>
                            <th>Last Promotion</th>
                            <th>Processed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($promotions as $log)
                            @php
                                $d = $log->details ?? [];
                                $emp = $employees->get($log->target_id);
                            @endphp
                            <tr>
                                <td>{{ $log->created_at->format('M d, Y H:i') }}</td>
                                <td><a href="{{ route("{$routePrefix}.plantilla.service-trail", ['employee_id' => $log->target_id]) }}">{{ $employeeName($log->target_id) }}</a></td>
                                <td>
                                    @if(!empty($d['from']))
                                        {{ $d['from']['title'] ?? '-' }}<br>
                                        <small class="text-muted">SG {{ $d['from']['salary_grade'] ?? '?' }} Step {{ $d['from']['step'] ?? '?' }}{{ !empty($d['from']['item_number']) ? ' · Item '.$d['from']['item_number'] : '' }}</small>
                                    @else
                                        <span class="text-muted">(no previous position)</span>
                                    @endif
                                </td>
                                <td>
                                    {{ $d['to']['title'] ?? '-' }}<br>
                                    <small class="text-muted">SG {{ $d['to']['salary_grade'] ?? '?' }} Step {{ $d['to']['step'] ?? '?' }}{{ !empty($d['to']['item_number']) ? ' · Item '.$d['to']['item_number'] : '' }}</small>
                                </td>
                                <td>{{ !empty($d['effective_date']) ? \Illuminate\Support\Carbon::parse($d['effective_date'])->format('M d, Y') : '-' }}</td>
                                <td>{{ $emp?->date_of_original_appointment?->format('M d, Y') ?? '-' }}</td>
                                <td>{{ $emp?->date_of_last_promotion?->format('M d, Y') ?? '-' }}</td>
                                <td>{{ $log->actor->name ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-hris.table-pagination :paginator="$promotions" />
        @else
            <p class="empty-state">No promotions {{ request('promotion_search') ? 'match your search' : 'recorded yet. Promotions made with the Promote button will appear here' }}.</p>
        @endif
    </section>

    {{-- Vacant positions --}}
    <section class="payroll-section">
        <h2><i class="fas fa-chair"></i>Vacant Positions ({{ $vacantPositions->total() }})</h2>
        <form method="GET" action="{{ route("{$routePrefix}.plantilla.reports") }}" class="plantilla-filter-form" style="margin-bottom:14px">
            <input type="text" name="vacant_search" value="{{ request('vacant_search') }}" placeholder="Search item no., title, or dept..." class="hris-search-input" style="min-width:260px">
            <select name="vacant_department" class="hris-filter-select">
                <option value="">All Departments</option>
                @foreach($departments as $dept)
                    <option value="{{ $dept }}" @selected(request('vacant_department') === $dept)>{{ \Illuminate\Support\Str::limit($dept, 60) }}</option>
                @endforeach
            </select>
            <input type="hidden" name="promotion_search" value="{{ request('promotion_search') }}">
            <input type="hidden" name="activity_search" value="{{ request('activity_search') }}">
            <input type="hidden" name="activity_action" value="{{ request('activity_action') }}">
            <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm"><i class="fas fa-filter"></i> Filter</button>
            @if(request()->hasAny(['vacant_search', 'vacant_department']))
                <a href="{{ route("{$routePrefix}.plantilla.reports", request()->except(['vacant_search', 'vacant_department', 'vacant_page'])) }}" class="hris-btn hris-btn-secondary hris-btn-sm">Clear</a>
            @endif
        </form>
        @if($vacantPositions->count())
            <div class="plantilla-panel overflow-x-auto">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <th>Item No.</th>
                            <th>Position Title</th>
                            <th>Department / Office</th>
                            <th>SG</th>
                            <th>Budgeted Step</th>
                            <th>Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($vacantPositions as $vp)
                            <tr>
                                <td>@if($vp->item_number)<span class="item-badge">{{ $vp->item_number }}</span>@else -@endif</td>
                                <td><a href="{{ route("{$routePrefix}.plantilla.show", $vp->id) }}">{{ $vp->title }}</a></td>
                                <td>{{ $vp->department ?: '-' }}</td>
                                <td><span class="sg-badge">SG {{ $vp->salary_grade }}</span></td>
                                <td>{{ $vp->step }}</td>
                                <td>{{ ucwords(str_replace('_', ' ', $vp->employment_type)) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-hris.table-pagination :paginator="$vacantPositions" />
        @else
            <p class="empty-state">{{ request()->hasAny(['vacant_search', 'vacant_department']) ? 'No vacant positions match your filters.' : 'No vacant positions -every plantilla item has an active incumbent.' }}</p>
        @endif
    </section>

    {{-- Activity log --}}
    <section class="payroll-section">
        <h2><i class="fas fa-clock-rotate-left"></i>Recent Assignment Activity</h2>
        <form method="GET" action="{{ route("{$routePrefix}.plantilla.reports") }}" class="plantilla-filter-form" style="margin-bottom:14px">
            <input type="text" name="activity_search" value="{{ request('activity_search') }}" placeholder="Search employee name or EmpNo..." class="hris-search-input" style="min-width:260px">
            <select name="activity_action" class="hris-filter-select">
                <option value="">All Actions</option>
                @foreach(['promotion' => 'Promotion', 'assignment_created' => 'Assignment Created', 'assignment_updated' => 'Assignment Updated', 'assignment_removed' => 'Assignment Removed'] as $value => $label)
                    <option value="{{ $value }}" @selected(request('activity_action') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <input type="hidden" name="promotion_search" value="{{ request('promotion_search') }}">
            <input type="hidden" name="vacant_search" value="{{ request('vacant_search') }}">
            <input type="hidden" name="vacant_department" value="{{ request('vacant_department') }}">
            <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm"><i class="fas fa-filter"></i> Filter</button>
            @if(request()->hasAny(['activity_search', 'activity_action']))
                <a href="{{ route("{$routePrefix}.plantilla.reports", request()->except(['activity_search', 'activity_action', 'activity_page'])) }}" class="hris-btn hris-btn-secondary hris-btn-sm">Clear</a>
            @endif
        </form>
        @if($activity->count())
            <div class="plantilla-panel overflow-x-auto">
                <table class="hris-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Action</th>
                            <th>Employee</th>
                            <th>Position</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($activity as $log)
                            @php $d = $log->details ?? []; @endphp
                            <tr>
                                <td>{{ $log->created_at->format('M d, Y H:i') }}</td>
                                <td>{{ ucwords(str_replace('_', ' ', $log->action)) }}</td>
                                <td>{{ $employeeName($log->target_id) }}</td>
                                <td>
                                    {{ $d['to']['title'] ?? $d['title'] ?? '-' }}
                                    @php $item = $d['to']['item_number'] ?? $d['item_number'] ?? null; @endphp
                                    @if($item)<small class="text-muted">(Item {{ $item }})</small>@endif
                                </td>
                                <td>{{ $log->actor->name ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-hris.table-pagination :paginator="$activity" />
        @else
            <p class="empty-state">{{ request()->hasAny(['activity_search', 'activity_action']) ? 'No activity matches your filters.' : 'No assignment activity logged yet.' }}</p>
        @endif
    </section>
@endsection
