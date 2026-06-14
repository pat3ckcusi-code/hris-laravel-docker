@props(['balances'])

@if($balances->isEmpty())
    <p style="color:#16a34a;padding:0.5rem 0;">&#x2713; No employees with critically low balances.</p>
@else
<div class="table-responsive">
    <table class="hris-table" style="width:100%">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Department</th>
                <th class="text-center" style="width:72px">VL</th>
                <th class="text-center" style="width:72px">SL</th>
            </tr>
        </thead>
        <tbody>
            @foreach($balances as $b)
                @php
                    $empName = trim(($b->last_name ?? '') . ', ' . ($b->first_name ?? ''));
                    $vlLow   = $b->VL !== null && $b->VL < 2;
                    $slLow   = $b->SL !== null && $b->SL < 2;
                @endphp
                <tr>
                    <td style="font-weight:600">{{ $empName ?: '—' }}</td>
                    <td style="color:#64748b;font-size:0.85rem">{{ $b->Dept_name ?? '—' }}</td>
                    <td class="text-center" @style(['color:#dc2626;font-weight:700' => $vlLow])>
                        {{ $b->VL !== null ? number_format((float) $b->VL, 3) : '—' }}
                    </td>
                    <td class="text-center" @style(['color:#dc2626;font-weight:700' => $slLow])>
                        {{ $b->SL !== null ? number_format((float) $b->SL, 3) : '—' }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
