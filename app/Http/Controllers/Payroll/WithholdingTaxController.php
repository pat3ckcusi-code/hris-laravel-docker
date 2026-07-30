<?php

namespace App\Http\Controllers\Payroll;

use App\Exports\WithholdingTaxImportTemplate;
use App\Http\Controllers\Controller;
use App\Models\Deduction;
use App\Models\HRAuditTrail;
use App\Models\User;
use App\Models\WithholdingTax;
use App\Services\WithholdingTaxImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Withholding tax is no longer computed by PayrollComputationService - it's
 * already computed by Accounting and uploaded here monthly, per employee,
 * for a whole year (Jan-Dec) at a time. The grid/filter UI itself lives
 * directly on the BIR Deduction row's own show page (DeductionsController::show())
 * rather than a separate page - this controller only handles the actions
 * that page's forms submit to (single-cell correction, template download,
 * bulk upload). See "Replace computed BIR withholding tax with an
 * Accounting-uploaded monthly table".
 */
class WithholdingTaxController extends Controller
{
    /**
     * Every action here redirects back to the BIR row's own show page -
     * there's only ever one, so no id needs threading through requests.
     */
    private function backToBirRow(array $params = []): RedirectResponse
    {
        $birId = Deduction::where('mandatory_key', 'bir')->value('id');

        return redirect()->route('payroll.contributions.show', array_merge(['contribution' => $birId], $params));
    }

    /**
     * Add a single employee/month entry directly from clicking an empty grid
     * cell - the counterpart to update() for a cell that has no uploaded
     * value yet. updateOrCreate (not a plain create) guards against a
     * double-submit racing the table's own unique (employee_id, year, month)
     * constraint.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'employee_id' => 'required|integer|exists:users,id',
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'required|integer|min:1|max:12',
            'amount' => 'required|numeric|min:0',
        ]);

        $withholdingTax = WithholdingTax::updateOrCreate(
            ['employee_id' => $request->employee_id, 'year' => $request->year, 'month' => $request->month],
            ['amount' => $request->amount, 'uploaded_by' => $request->user()->id]
        );

        HRAuditTrail::create([
            'actor_user_id' => $request->user()->id,
            'module' => 'payroll',
            'action' => 'withholding_tax_updated',
            'target_type' => WithholdingTax::class,
            'target_id' => $withholdingTax->id,
            'details' => [
                'employee_id' => $withholdingTax->employee_id,
                'year' => $withholdingTax->year,
                'month' => $withholdingTax->month,
                'amount' => $withholdingTax->amount,
            ],
        ]);

        return $this->backToBirRow(['year' => $withholdingTax->year])
            ->with('status', 'Withholding tax entry added.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate(['amount' => 'required|numeric|min:0']);

        $withholdingTax = WithholdingTax::findOrFail($id);
        $withholdingTax->update([
            'amount' => $request->amount,
            'uploaded_by' => $request->user()->id,
        ]);

        HRAuditTrail::create([
            'actor_user_id' => $request->user()->id,
            'module' => 'payroll',
            'action' => 'withholding_tax_updated',
            'target_type' => WithholdingTax::class,
            'target_id' => $withholdingTax->id,
            'details' => [
                'employee_id' => $withholdingTax->employee_id,
                'year' => $withholdingTax->year,
                'month' => $withholdingTax->month,
                'amount' => $withholdingTax->amount,
            ],
        ]);

        return $this->backToBirRow(['year' => $withholdingTax->year])
            ->with('status', 'Withholding tax entry updated.');
    }

    public function downloadTemplate(Request $request)
    {
        $year = (int) $request->query('year', now()->year);

        $employees = User::active()->orderBy('name')->get(['id', 'name', 'EmpNo']);
        $existingByEmployee = WithholdingTax::where('year', $year)->get()->groupBy('employee_id');

        return Excel::download(
            new WithholdingTaxImportTemplate($year, $employees, $existingByEmployee),
            "withholding_tax_{$year}.xlsx"
        );
    }

    public function upload(Request $request, WithholdingTaxImportService $service): RedirectResponse
    {
        $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
            'withholding_tax_file' => 'required|file|mimes:xlsx,xls,csv|max:2048',
        ]);

        $result = $service->import((int) $request->year, $request->file('withholding_tax_file'), $request->user());

        HRAuditTrail::create([
            'actor_user_id' => $request->user()->id,
            'module' => 'payroll',
            'action' => 'withholding_tax_uploaded',
            'target_type' => WithholdingTax::class,
            'details' => [
                'year' => (int) $request->year,
                'created' => $result['created'],
                'updated' => $result['updated'],
                'unmatched' => $result['unmatched'],
            ],
        ]);

        $message = "{$result['created']} entr".($result['created'] === 1 ? 'y' : 'ies')." created, {$result['updated']} updated.";
        if (! empty($result['unmatched'])) {
            $message .= ' Employee Agency Number not found: '.implode(', ', $result['unmatched']).'.';
        }
        if (! empty($result['mismatchedNames'])) {
            $message .= ' Name mismatch - please double-check: '.implode('; ', $result['mismatchedNames']).'.';
        }

        return $this->backToBirRow(['year' => (int) $request->year])
            ->with('status', $message);
    }
}
