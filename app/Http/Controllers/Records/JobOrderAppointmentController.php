<?php

namespace App\Http\Controllers\Records;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\JobOrderAppointment;
use App\Models\User;
use App\Services\JobOrderAppointmentService;
use App\Services\JobOrderRosterExportService;
use App\Support\RoleNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JobOrderAppointmentController extends Controller
{
    public function __construct(
        private JobOrderAppointmentService $service,
        private JobOrderRosterExportService $rosterExportService,
    ) {}

    public function index(Request $request, User $user): JsonResponse
    {
        $this->ensureRecordsManager($request);

        $appointments = JobOrderAppointment::forUser($user->id)
            ->orderByDesc('period_from')
            ->get()
            ->map(fn (JobOrderAppointment $appointment) => $this->toArray($appointment, $user));

        return response()->json(['appointments' => $appointments]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $this->ensureRecordsManager($request);

        if ($user->employee_type !== 'Job Orders') {
            return response()->json([
                'success' => false,
                'message' => 'Appointments can only be recorded for Job Order employees.',
            ], 422);
        }

        $validated = $this->validateAppointment($request);

        $appointment = $this->service->create($user, $validated, $request->user()?->id);

        HRAuditTrail::create([
            'actor_user_id' => $request->user()?->id,
            'module' => 'records',
            'action' => 'job_order_appointment_created',
            'target_type' => User::class,
            'target_id' => $user->id,
            'details' => $this->auditDetails($user, $appointment),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Job Order appointment recorded.',
            'appointment' => $this->toArray($appointment, $user),
        ]);
    }

    public function update(Request $request, User $user, JobOrderAppointment $appointment): JsonResponse
    {
        $this->ensureRecordsManager($request);

        abort_unless($appointment->user_id === $user->id, 404);

        $validated = $this->validateAppointment($request);

        $appointment = $this->service->update($appointment, $validated);

        HRAuditTrail::create([
            'actor_user_id' => $request->user()?->id,
            'module' => 'records',
            'action' => 'job_order_appointment_updated',
            'target_type' => User::class,
            'target_id' => $user->id,
            'details' => $this->auditDetails($user, $appointment),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Job Order appointment updated.',
            'appointment' => $this->toArray($appointment, $user),
        ]);
    }

    public function destroy(Request $request, User $user, JobOrderAppointment $appointment): JsonResponse
    {
        $this->ensureRecordsManager($request);

        abort_unless($appointment->user_id === $user->id, 404);

        $details = $this->auditDetails($user, $appointment);
        $appointment->delete();

        HRAuditTrail::create([
            'actor_user_id' => $request->user()?->id,
            'module' => 'records',
            'action' => 'job_order_appointment_deleted',
            'target_type' => User::class,
            'target_id' => $user->id,
            'details' => $details,
        ]);

        return response()->json(['status' => 'success', 'message' => 'Job Order appointment deleted.']);
    }

    public function rosterForm(Request $request): View
    {
        $this->ensureRecordsManager($request);

        $filters = $this->rosterFilters($request);
        $rows = $this->rosterExportService->getRows($filters);

        return view('dashboards.records-manager-job-order-roster', [
            'rows' => $rows,
            'departments' => Department::query()->orderBy('Dept_name')->get(),
            'filters' => $filters,
        ]);
    }

    public function exportRoster(Request $request): StreamedResponse
    {
        $this->ensureRecordsManager($request);

        $filters = $this->rosterFilters($request);

        return $this->rosterExportService->generateExcelResponse($filters, $request->user());
    }

    private function rosterFilters(Request $request): array
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'array'],
            'department_id.*' => ['integer', 'exists:departments,Dept_id'],
            'office' => ['nullable', 'string', 'max:255'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
        ]);

        return array_filter($validated, fn ($value) => is_array($value) ? count($value) > 0 : ($value !== null && $value !== ''));
    }

    private function validateAppointment(Request $request): array
    {
        return $request->validate([
            'designation' => ['nullable', 'string', 'max:255'],
            'office' => ['nullable', 'string', 'max:255'],
            'funding_source' => ['nullable', 'string', 'max:255'],
            'rate_per_day' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'rate_note' => ['nullable', 'string', 'max:50'],
            'period_from' => ['required', 'date'],
            'period_until' => ['required', 'date', 'after_or_equal:period_from'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function auditDetails(User $user, JobOrderAppointment $appointment): array
    {
        return [
            'employee_name' => $user->name,
            'employee_no' => $user->EmpNo,
            'period_from' => $appointment->period_from?->toDateString(),
            'period_until' => $appointment->period_until?->toDateString(),
            'office' => $appointment->office,
            'rate_per_day' => $appointment->rate_per_day,
            'rate_note' => $appointment->rate_note,
            'remarks' => $appointment->remarks,
        ];
    }

    private function toArray(JobOrderAppointment $appointment, User $user): array
    {
        return [
            'id' => $appointment->id,
            'designation' => $appointment->designation,
            'office' => $appointment->office,
            'funding_source' => $appointment->funding_source,
            'rate_per_day' => (float) $appointment->rate_per_day,
            'rate_note' => $appointment->rate_note,
            'rate_label' => $appointment->rateLabel(),
            'period_from' => $appointment->period_from?->format('Y-m-d'),
            'period_until' => $appointment->period_until?->format('Y-m-d'),
            'remarks' => $appointment->remarks,
            'is_current' => $appointment->isCurrent(),
            'update_url' => route('dashboard.records-manager.job-order-appointments.update', [$user, $appointment]),
            'delete_url' => route('dashboard.records-manager.job-order-appointments.destroy', [$user, $appointment]),
        ];
    }

    private function ensureRecordsManager(Request $request): void
    {
        $role = RoleNormalizer::normalize((string) $request->user()->access_level);
        abort_unless($role === 'records manager', 403, 'Only Records Manager can access this section.');
    }
}
