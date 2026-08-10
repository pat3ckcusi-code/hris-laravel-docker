<?php

namespace App\Http\Controllers\Payroll;

use App\Exports\LoanBillingImportTemplate;
use App\Http\Controllers\Controller;
use App\Models\Deduction;
use App\Models\Loan;
use App\Models\User;
use App\Services\LoanBillingImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class LoanController extends Controller
{
    /**
     * Master list of loans across every provider/employee - a real page for
     * managing loans instead of only being reachable via each deduction
     * type's own show page. See "Dedicated Loans page; remove the Loans
     * count column from Deductions".
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $provider = (string) $request->query('provider', '');

        $loans = Loan::with(['employee', 'deduction', 'billingHistory'])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sq) use ($search) {
                    $sq->whereHas('employee', fn ($eq) => $eq->where('name', 'like', "%{$search}%")->orWhere('EmpNo', 'like', "%{$search}%"))
                        ->orWhereHas('deduction', fn ($dq) => $dq->where('type', 'like', "%{$search}%")->orWhere('provider', 'like', "%{$search}%"));
                });
            })
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($provider !== '', fn ($q) => $q->where('deduction_id', $provider))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $providers = Deduction::where('deduction_category', 'loan')->withCount('loans')->orderBy('type')->get();

        $stats = [
            'providers' => $providers->count(),
            'total_loans' => Loan::count(),
            'active_loans' => Loan::where('status', 'active')->count(),
            'outstanding_balance' => (float) Loan::where('status', 'active')->get()->sum('balance'),
        ];

        return view('payroll.loans', compact('loans', 'search', 'status', 'provider', 'providers', 'stats'));
    }

    public function store(Request $request, int $deductionId): RedirectResponse
    {
        $deduction = Deduction::findOrFail($deductionId);

        if (! $deduction->is_active) {
            return redirect()->route('payroll.contributions.show', $deductionId)
                ->with('error', 'This deduction type is inactive. Reactivate it before assigning new employees.');
        }

        $request->validate([
            'employee_id' => 'required|integer|exists:users,id',
            'balance' => 'required|numeric|min:0',
            'monthly_payment' => 'required|numeric|min:0',
            'status' => 'required|in:active,paid,suspended',
        ]);

        $exists = Loan::where('employee_id', $request->employee_id)
            ->where('deduction_id', $deduction->id)
            ->exists();

        if ($exists) {
            return redirect()->route('payroll.contributions.show', $deductionId)
                ->with('error', 'This employee already has a loan under this deduction type.');
        }

        Loan::create([
            'employee_id' => $request->employee_id,
            'deduction_id' => $deduction->id,
            'balance' => $request->balance,
            'monthly_payment' => $request->monthly_payment,
            'status' => $request->status,
        ]);

        return redirect()->route('payroll.contributions.show', $deductionId)
            ->with('status', 'Loan assigned to employee.');
    }

    /**
     * Bulk-register several employees as borrowers under a provider without
     * needing their balance/monthly payment up front - creates placeholder
     * (₱0) Loan rows purely so the next billing template download has their
     * Employee Agency Number/Name/Department pre-filled, leaving only
     * Monthly Payment/Balance for the encoder to type. PayrollComputationService
     * excludes balance=0 loans, so a placeholder never affects payroll until
     * it's actually billed. See "Bulk 'Add to Roster' for Loans".
     */
    public function bulkAssign(Request $request, int $deductionId): RedirectResponse
    {
        $deduction = Deduction::findOrFail($deductionId);

        if ($deduction->deduction_category !== 'loan') {
            return redirect()->route('payroll.contributions.show', $deductionId)
                ->with('error', 'Adding employees to a roster only applies to Loan-category deduction types.');
        }

        if (! $deduction->is_active) {
            return redirect()->route('payroll.contributions.show', $deductionId)
                ->with('error', 'This deduction type is inactive. Reactivate it before adding employees.');
        }

        $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer|distinct|exists:users,id',
        ]);

        $submittedIds = array_unique(array_map('intval', $request->input('employee_ids', [])));
        $eligibleIds = User::active()->whereIn('id', $submittedIds)->pluck('id')->all();

        $alreadyAssignedIds = Loan::where('deduction_id', $deduction->id)
            ->whereIn('employee_id', $eligibleIds)
            ->pluck('employee_id')
            ->all();

        $toAssign = array_values(array_diff($eligibleIds, $alreadyAssignedIds));

        foreach ($toAssign as $employeeId) {
            Loan::create([
                'employee_id' => $employeeId,
                'deduction_id' => $deduction->id,
                'balance' => 0,
                'monthly_payment' => 0,
                'status' => 'active',
            ]);
        }

        $assignedCount = count($toAssign);
        $skippedCount = count($alreadyAssignedIds);

        if ($assignedCount === 0) {
            $message = $skippedCount > 0
                ? "No new employees added - all {$skippedCount} selected employee(s) already have a loan under this provider."
                : 'No eligible employees were selected.';

            return redirect()->route('payroll.contributions.show', $deductionId)->with('error', $message);
        }

        $message = "Added {$assignedCount} employee(s) to this provider's roster - their Employee Agency Number, Name, and Department will be pre-filled the next time you download the billing template.";
        if ($skippedCount > 0) {
            $message .= " {$skippedCount} already had a loan under this provider and were skipped.";
        }

        return redirect()->route('payroll.contributions.show', $deductionId)->with('status', $message);
    }

    public function downloadBillingTemplate(Request $request, int $deductionId)
    {
        $deduction = Deduction::findOrFail($deductionId);

        $billingMonth = $request->query('month', '');
        if (! preg_match('/^\d{4}-\d{2}$/', $billingMonth)) {
            $billingMonth = now()->format('Y-m');
        }

        $employees = Loan::where('deduction_id', $deduction->id)
            ->where('status', 'active')
            ->with('employee.department')
            ->get()
            ->pluck('employee')
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();

        $filename = Str::slug($deduction->type).'_billing_'.$billingMonth.'.xlsx';

        return Excel::download(new LoanBillingImportTemplate($deduction, $billingMonth, $employees), $filename);
    }

    /**
     * Bulk-upload a provider's monthly billing (EmpNo, Monthly Payment,
     * Balance) - overwrites an existing employee's loan with this month's
     * figures, creates a new Loan for an EmpNo not yet on this provider, and
     * records a real per-month snapshot. See "Monthly loan billing upload,
     * with real per-month history" and LoanBillingImportService.
     */
    public function uploadBilling(Request $request, int $deductionId, LoanBillingImportService $importService): RedirectResponse
    {
        $deduction = Deduction::findOrFail($deductionId);

        if ($deduction->deduction_category !== 'loan') {
            return redirect()->route('payroll.contributions.show', $deductionId)
                ->with('error', 'Billing upload only applies to Loan-category deduction types.');
        }

        if (! $deduction->is_active) {
            return redirect()->route('payroll.contributions.show', $deductionId)
                ->with('error', 'This deduction type is inactive. Reactivate it before uploading billing.');
        }

        $request->validate([
            'billing_month' => 'required|date_format:Y-m',
            'billing_file' => 'required|file|mimes:xlsx,xls,csv|max:2048',
        ]);

        $result = $importService->import($deduction, $request->file('billing_file'), $request->user(), $request->input('billing_month'));

        $message = "{$result['updated']} loan(s) updated, {$result['created']} new loan(s) added.";
        if (! empty($result['unmatched'])) {
            $message .= ' Employee Agency Number not found: '.implode(', ', $result['unmatched']).'.';
        }
        if (! empty($result['mismatchedNames'])) {
            $message .= ' Name mismatch - please double-check: '.implode('; ', $result['mismatchedNames']).'.';
        }

        return redirect()->route('payroll.contributions.show', $deductionId)
            ->with('status', $message);
    }

    public function update(Request $request, int $deductionId, int $id): RedirectResponse
    {
        $loan = Loan::where('deduction_id', $deductionId)->findOrFail($id);

        $request->validate([
            'balance' => 'required|numeric|min:0',
            'monthly_payment' => 'required|numeric|min:0',
            'status' => 'required|in:active,paid,suspended',
        ]);

        $loan->update([
            'balance' => $request->balance,
            'monthly_payment' => $request->monthly_payment,
            'status' => $request->status,
        ]);

        return redirect($request->input('_redirect', route('payroll.contributions.show', $deductionId)))
            ->with('status', 'Loan updated.');
    }

    public function destroy(Request $request, int $deductionId, int $id): RedirectResponse
    {
        Loan::where('deduction_id', $deductionId)->findOrFail($id)->delete();

        return redirect($request->input('_redirect', route('payroll.contributions.show', $deductionId)))
            ->with('status', 'Loan removed.');
    }
}
