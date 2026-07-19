<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Jobs\WorkSuspensionRecomputeJob;
use App\Models\WorkSuspension;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkSuspensionController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('search', ''));
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $type = $request->input('type');

        $query = WorkSuspension::with('creator')->orderBy('suspension_date', 'desc');

        if ($search !== '') {
            $query->where('reason', 'like', "%{$search}%");
        }
        if ($dateFrom) {
            $query->whereDate('suspension_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('suspension_date', '<=', $dateTo);
        }
        if ($type) {
            $query->where('type', $type);
        }

        $suspensions = $query->paginate(25)->withQueryString();
        $filters = compact('search', 'dateFrom', 'dateTo', 'type');

        return view('attendance.work-suspension.index', compact('suspensions', 'filters'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $exists = WorkSuspension::whereDate('suspension_date', $validated['suspension_date'])->exists();
        if ($exists) {
            return back()->withErrors(['suspension_date' => 'A work suspension is already declared for this date.'])->withInput();
        }

        $validated['created_by'] = $request->user()->id;
        WorkSuspension::create($validated);

        WorkSuspensionRecomputeJob::dispatch($validated['suspension_date']);

        return back()->with('success', 'Work suspension declared and DTRs are being recomputed.');
    }

    public function update(Request $request, WorkSuspension $workSuspension): RedirectResponse
    {
        $validated = $this->validated($request);

        $exists = WorkSuspension::whereDate('suspension_date', $validated['suspension_date'])
            ->where('id', '!=', $workSuspension->id)
            ->exists();
        if ($exists) {
            return back()->withErrors(['suspension_date' => 'A work suspension is already declared for this date.'])->withInput();
        }

        $oldDate = $workSuspension->suspension_date->format('Y-m-d');
        $workSuspension->update($validated);

        WorkSuspensionRecomputeJob::dispatch($validated['suspension_date']);
        if ($oldDate !== $validated['suspension_date']) {
            WorkSuspensionRecomputeJob::dispatch($oldDate);
        }

        return back()->with('success', 'Work suspension updated and DTRs are being recomputed.');
    }

    public function destroy(WorkSuspension $workSuspension): RedirectResponse
    {
        $date = $workSuspension->suspension_date->format('Y-m-d');
        $workSuspension->delete();

        WorkSuspensionRecomputeJob::dispatch($date);

        return back()->with('success', 'Work suspension removed and DTRs are being recomputed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'suspension_date' => ['required', 'date'],
            'suspension_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:1000'],
            'type' => ['required', Rule::in(['weather', 'event', 'other'])],
        ]);
    }
}
