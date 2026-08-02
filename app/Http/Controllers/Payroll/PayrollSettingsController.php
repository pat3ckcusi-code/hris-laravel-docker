<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PayrollSettingsController extends Controller
{
    // The only keys this page manages - read by
    // PayrollFormExportService::applySignatoryOverrides() for the General Payroll export.
    private const SIGNATORY_KEYS = [
        'payroll_signatory_mayor_name',
        'payroll_signatory_mayor_designation',
        'payroll_signatory_accountant_name',
        'payroll_signatory_accountant_designation',
        'payroll_signatory_treasurer_name',
        'payroll_signatory_treasurer_designation',
        'payroll_signatory_cash_clerk_names',
        'payroll_signatory_cash_clerk_designation',
    ];

    public function index(): View
    {
        $settings = PayrollSetting::whereIn('key', self::SIGNATORY_KEYS)->get()->keyBy('key');

        return view('payroll.settings', compact('settings'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            array_fill_keys(self::SIGNATORY_KEYS, 'nullable|string|max:255')
        );

        foreach (self::SIGNATORY_KEYS as $key) {
            PayrollSetting::updateOrCreate(['key' => $key], ['value' => $validated[$key] ?? null]);
        }

        return redirect()->route('payroll.settings.index')
            ->with('status', 'Signatories updated.');
    }
}
