<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PayrollSettingsController extends Controller
{
    public function index(): View
    {
        $settings = PayrollSetting::orderBy('key')->get()->keyBy('key');

        return view('payroll.settings', compact('settings'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.settings.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'key' => 'required|string|max:150|unique:payroll_settings,key',
            'value' => 'nullable|string',
        ]);

        PayrollSetting::create($request->only('key', 'value'));

        return redirect()->route('payroll.settings.index')
            ->with('status', 'Setting saved.');
    }

    public function show(int $id): RedirectResponse
    {
        return redirect()->route('payroll.settings.index');
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.settings.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'value' => 'nullable|string',
        ]);

        PayrollSetting::findOrFail($id)->update(['value' => $request->value]);

        return redirect()->route('payroll.settings.index')
            ->with('status', 'Setting updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        PayrollSetting::findOrFail($id)->delete();

        return redirect()->route('payroll.settings.index')
            ->with('status', 'Setting deleted.');
    }
}
