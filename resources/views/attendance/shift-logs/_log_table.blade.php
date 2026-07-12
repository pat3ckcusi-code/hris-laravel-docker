@php
    $th = 'padding:0.55rem 0.6rem;border:1px solid #cbd5e1;text-align:center;font-size:0.75rem;font-weight:700;line-height:1.35;vertical-align:middle;';
    $td = 'padding:0.5rem 0.65rem;border:1px solid #e2e8f0;text-align:center;vertical-align:middle;';

    $actionColors = [
        'access_granted' => ['bg' => '#d1fae5', 'color' => '#065f46'],
        'access_revoked' => ['bg' => '#fee2e2', 'color' => '#991b1b'],
        'shift_assigned' => ['bg' => '#dbeafe', 'color' => '#1e40af'],
        'dtr_exemption_toggled' => ['bg' => '#fef3c7', 'color' => '#92400e'],
        'shift_schedule_updated' => ['bg' => '#e0e7ff', 'color' => '#3730a3'],
        'rotation_generated' => ['bg' => '#e0e7ff', 'color' => '#3730a3'],
        'shift_template_created' => ['bg' => '#dcfce7', 'color' => '#166534'],
        'shift_template_updated' => ['bg' => '#fef9c3', 'color' => '#713f12'],
        'shift_template_deleted' => ['bg' => '#fee2e2', 'color' => '#991b1b'],
        'shift_assignment_corrected' => ['bg' => '#e0f2fe', 'color' => '#0369a1'],
    ];
@endphp

<div class="tile" style="padding:0;overflow:hidden;margin-bottom:1rem;">
    <div style="padding:1rem 1.25rem 0.75rem;border-bottom:1px solid #e5e7eb;">
        <div style="font-weight:700;font-size:0.95rem;text-transform:uppercase;letter-spacing:.03em;">{{ $title }}</div>
        <div style="font-size:0.82rem;color:#6b7280;margin-top:2px;">{{ $subtitle }}</div>
    </div>
    <div style="padding:0.5rem 1rem 0.75rem;overflow-x:auto;">
        <table style="width:100%;font-size:0.82rem;border-collapse:collapse;">
            <thead>
                <tr style="background:#bdd7ee;">
                    <th style="{{ $th }}white-space:nowrap;">Date/Time</th>
                    <th style="{{ $th }}">Action</th>
                    <th style="{{ $th }}text-align:left;">Target</th>
                    <th style="{{ $th }}text-align:left;">Details</th>
                    <th style="{{ $th }}text-align:left;">Actor</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    @php $colors = $actionColors[$log->action] ?? ['bg' => '#f1f5f9', 'color' => '#475569']; @endphp
                    @php
                        // Seeded/demo role accounts (see UsersTableSeeder) only ever set
                        // `name`, never first_name/last_name - trim() alone would render
                        // an empty cell for them instead of falling back to `name`.
                        $actorName = $log->actor
                            ? (trim("{$log->actor->first_name} {$log->actor->last_name}") ?: $log->actor->name)
                            : null;
                    @endphp
                    <tr>
                        <td style="{{ $td }}white-space:nowrap;color:#6b7280;">{{ $log->created_at?->format('M d, Y g:i A') }}</td>
                        <td style="{{ $td }}">
                            <span style="display:inline-block;padding:.25rem .6rem;border-radius:9999px;font-size:.72rem;font-weight:600;
                                         background:{{ $colors['bg'] }};color:{{ $colors['color'] }};">
                                {{ $log->action_label }}
                            </span>
                        </td>
                        <td style="{{ $td }}text-align:left;font-weight:600;">{{ $log->target_label }}</td>
                        <td style="{{ $td }}text-align:left;color:#374151;">{{ $log->summary }}</td>
                        <td style="{{ $td }}text-align:left;">{{ $actorName ?: '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="{{ $td }}color:#94a3b8;">{{ $emptyText ?? 'No shift changes logged yet.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="padding:0.75rem 1.25rem;">
        {{ $logs->links() }}
    </div>
</div>
