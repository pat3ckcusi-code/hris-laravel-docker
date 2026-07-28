<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\HRAuditTrail;
use App\Models\SalaryMatrix;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalaryMatrixController extends Controller
{
    public function index(Request $request): View
    {
        $versions = SalaryMatrix::select('effective_date', 'ordinance_reference')
            ->distinct()
            ->orderByDesc('effective_date')
            ->get();

        $validated = $request->validate([
            'version' => ['nullable', 'date'],
        ]);
        $selected = $validated['version'] ?? optional($versions->first())->effective_date?->toDateString();

        $matrix = SalaryMatrix::whereDate('effective_date', $selected)
            ->orderBy('sg')
            ->orderBy('step')
            ->get()
            ->groupBy('sg');

        $activeOrdinance = $versions->first(fn ($v) => $v->effective_date->toDateString() === $selected)?->ordinance_reference;

        return view('payroll.salary-matrix', compact('matrix', 'versions', 'selected', 'activeOrdinance'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.salary-matrix.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'sg' => 'required|integer|min:1|max:33',
            'step' => 'required|integer|min:1|max:8',
            'effective_date' => 'required|date',
            'ordinance_reference' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0',
        ]);

        SalaryMatrix::updateOrCreate(
            $request->only('sg', 'step', 'effective_date'),
            $request->only('amount', 'ordinance_reference'),
        );

        return redirect()->route('payroll.salary-matrix.index', ['version' => $request->effective_date])
            ->with('status', 'Salary matrix entry saved.');
    }

    /**
     * Publish a full new rate tranche (e.g. an SSL/EO ordinance) in one
     * submission instead of 264 individual row edits. Sparse cells (a grade
     * with fewer than 8 steps, like SG 33) are simply skipped.
     */
    public function storeVersion(Request $request): RedirectResponse
    {
        $request->validate([
            'effective_date' => 'required|date',
            'ordinance_reference' => 'nullable|string|max:255',
            'amounts' => 'required|array',
            'amounts.*.*' => 'nullable|numeric|min:0',
        ]);

        $saved = 0;

        DB::transaction(function () use ($request, &$saved) {
            foreach ($request->input('amounts') as $sg => $steps) {
                foreach ($steps as $step => $amount) {
                    if ($amount === null || $amount === '') {
                        continue;
                    }

                    SalaryMatrix::updateOrCreate(
                        ['sg' => (int) $sg, 'step' => (int) $step, 'effective_date' => $request->effective_date],
                        ['amount' => $amount, 'ordinance_reference' => $request->ordinance_reference],
                    );
                    $saved++;
                }
            }

            HRAuditTrail::create([
                'actor_user_id' => auth()->id(),
                'module' => 'payroll',
                'action' => 'salary_matrix_version_created',
                'target_type' => SalaryMatrix::class,
                'details' => [
                    'effective_date' => $request->effective_date,
                    'ordinance_reference' => $request->ordinance_reference,
                    'rows' => $saved,
                ],
            ]);
        });

        return redirect()->route('payroll.salary-matrix.index', ['version' => $request->effective_date])
            ->with('status', "New rate tranche published: {$saved} entries effective {$request->effective_date}.");
    }

    /**
     * Move every entry in an already-published tranche to a new effective
     * date (and/or update its ordinance reference) - store()/storeVersion()
     * can only create/touch a row at a given date, never retarget one, since
     * (sg, step, effective_date) is the lookup/uniqueness key. Rejected
     * up front (not left to a partial-transaction DB error) if the target
     * date already has any overlapping (sg, step) cell. year is set
     * explicitly alongside effective_date since SalaryMatrix::booted()'s
     * saving hook only derives whichever of the two is missing - both are
     * already set on an existing row, so neither branch would fire.
     */
    public function updateVersion(Request $request): RedirectResponse
    {
        $request->validate([
            'current_effective_date' => 'required|date',
            'effective_date' => 'required|date',
            'ordinance_reference' => 'nullable|string|max:255',
        ]);

        $rows = SalaryMatrix::where('effective_date', $request->current_effective_date)->get();

        if ($rows->isEmpty()) {
            return redirect()->route('payroll.salary-matrix.index')
                ->with('error', 'No tranche found for that date.');
        }

        if ($request->effective_date !== $request->current_effective_date) {
            $sourcePairs = $rows->map(fn ($r) => "{$r->sg}-{$r->step}")->all();
            $targetPairs = SalaryMatrix::where('effective_date', $request->effective_date)
                ->get(['sg', 'step'])
                ->map(fn ($r) => "{$r->sg}-{$r->step}")
                ->all();
            $collisions = array_intersect($sourcePairs, $targetPairs);

            if (! empty($collisions)) {
                return redirect()->route('payroll.salary-matrix.index', ['version' => $request->current_effective_date])
                    ->withInput()
                    ->with('error', count($collisions).' entries already exist on that date for the same grade/step — cannot move this tranche there.');
            }
        }

        $newYear = Carbon::parse($request->effective_date)->year;

        DB::transaction(function () use ($rows, $request, $newYear) {
            foreach ($rows as $row) {
                $row->update([
                    'effective_date' => $request->effective_date,
                    'year' => $newYear,
                    'ordinance_reference' => $request->ordinance_reference,
                ]);
            }

            HRAuditTrail::create([
                'actor_user_id' => auth()->id(),
                'module' => 'payroll',
                'action' => 'salary_matrix_version_updated',
                'target_type' => SalaryMatrix::class,
                'details' => [
                    'from_effective_date' => $request->current_effective_date,
                    'to_effective_date' => $request->effective_date,
                    'ordinance_reference' => $request->ordinance_reference,
                    'rows' => $rows->count(),
                ],
            ]);
        });

        return redirect()->route('payroll.salary-matrix.index', ['version' => $request->effective_date])
            ->with('status', "Tranche moved to {$request->effective_date} ({$rows->count()} entries updated).");
    }

    public function show(int $id): RedirectResponse
    {
        return redirect()->route('payroll.salary-matrix.index');
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.salary-matrix.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
        ]);

        SalaryMatrix::findOrFail($id)->update(['amount' => $request->amount]);

        return redirect()->route('payroll.salary-matrix.index')
            ->with('status', 'Salary matrix entry updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        SalaryMatrix::findOrFail($id)->delete();

        return redirect()->route('payroll.salary-matrix.index')
            ->with('status', 'Salary matrix entry deleted.');
    }
}
