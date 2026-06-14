<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Plantilla;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PlantillaController extends Controller
{
    public function index(): View
    {
        $plantillas = Plantilla::with('assignments.employee')
            ->orderBy('salary_grade')
            ->paginate(20);

        return view('payroll.plantilla', compact('plantillas'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.plantilla.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'salary_grade' => 'required|integer|min:1|max:33',
            'step' => 'required|integer|min:1|max:8',
            'employment_type' => 'required|string|max:100',
        ]);

        Plantilla::create($request->only('title', 'salary_grade', 'step', 'employment_type'));

        return redirect()->route('payroll.plantilla.index')
            ->with('status', 'Plantilla position created.');
    }

    public function show(int $id): View
    {
        $plantilla = Plantilla::with('assignments.employee')->findOrFail($id);
        $employees = User::orderBy('last_name')->get();

        return view('payroll.plantilla-show', compact('plantilla', 'employees'));
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.plantilla.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'salary_grade' => 'required|integer|min:1|max:33',
            'step' => 'required|integer|min:1|max:8',
            'employment_type' => 'required|string|max:100',
        ]);

        Plantilla::findOrFail($id)->update($request->only('title', 'salary_grade', 'step', 'employment_type'));

        return redirect()->route('payroll.plantilla.index')
            ->with('status', 'Plantilla position updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        Plantilla::findOrFail($id)->delete();

        return redirect()->route('payroll.plantilla.index')
            ->with('status', 'Plantilla position deleted.');
    }
}
