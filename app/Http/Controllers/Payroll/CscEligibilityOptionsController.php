<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\CscEligibilityOption;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CscEligibilityOptionsController extends Controller
{
    public function index(): View
    {
        $eligibilityOptions = CscEligibilityOption::withCount('plantillas')
            ->orderBy('id')
            ->paginate(20);

        return view('payroll.csc-eligibility', compact('eligibilityOptions'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.csc-eligibility.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'label' => 'required|string|max:150',
        ]);

        $label = trim($request->string('label'));
        $key = Str::slug($label, '_');

        if ($key === '') {
            throw ValidationException::withMessages([
                'label' => 'Label must contain at least one letter or number.',
            ]);
        }

        // key isn't a real form field (it's derived, not user-typed), so its
        // uniqueness can't be checked via Rule::unique on the request -
        // surface a collision as an error on the Label field instead. This
        // also transitively catches exact-duplicate labels, since identical
        // labels always derive identical keys.
        if (CscEligibilityOption::where('key', $key)->exists()) {
            throw ValidationException::withMessages([
                'label' => 'A similar category already exists.',
            ]);
        }

        CscEligibilityOption::create([
            'key' => $key,
            'label' => $label,
        ]);

        return redirect()->route('payroll.csc-eligibility.index')
            ->with('status', 'CSC Eligibility category created.');
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.csc-eligibility.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $option = CscEligibilityOption::findOrFail($id);

        $request->validate([
            'label' => [
                'required', 'string', 'max:150',
                Rule::unique('csc_eligibility_options', 'label')->ignore($option->id),
            ],
        ], [
            'label.unique' => 'A similar category already exists.',
        ]);

        // key is intentionally immutable after creation (see model docblock) -
        // only label is ever updated. Never reads $request->input('key') even
        // if a caller sends one.
        $option->update([
            'label' => trim($request->string('label')),
        ]);

        return redirect()->route('payroll.csc-eligibility.index')
            ->with('status', 'CSC Eligibility category updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $option = CscEligibilityOption::findOrFail($id);

        if ($option->plantillas()->exists()) {
            return redirect()->route('payroll.csc-eligibility.index')
                ->with('error', 'This CSC Eligibility category is assigned to one or more positions and cannot be deleted.');
        }

        $option->delete();

        return redirect()->route('payroll.csc-eligibility.index')
            ->with('status', 'CSC Eligibility category deleted.');
    }
}
