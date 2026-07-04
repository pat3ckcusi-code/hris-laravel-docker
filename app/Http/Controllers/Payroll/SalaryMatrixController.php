<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\HRAuditTrail;
use App\Models\SalaryMatrix;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
