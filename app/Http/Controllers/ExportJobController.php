<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessExportJob;
use App\Models\ExportJob;
use App\Models\User;
use App\Services\DepartmentService;
use App\Support\RoleNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportJobController extends Controller
{
    private const ADMIN_DTR_ROLES = ['hr manager', 'payroll manager', 'time keeper', 'records manager'];

    public function __construct(private readonly DepartmentService $departmentService) {}

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:pds,form48,form48_dept_zip,form48_dept,monitoring_matrix,leave_card,hr_reports'],
            'params' => ['required', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->authorizeExport($user, $data['type'], $data['params']);

        $job = ExportJob::createPending($user->id, $data['type'], $data['params']);
        ProcessExportJob::dispatch($job);

        return response()->json([
            'job_id' => $job->id,
            'status_url' => route('export-jobs.status', $job->id),
        ]);
    }

    public function status(string $id): JsonResponse
    {
        $job = ExportJob::findOrFail($id);
        abort_unless($job->user_id === auth()->id(), 403);

        $data = ['status' => $job->status];

        if ($job->status === 'completed') {
            $data['download_url'] = route('export-jobs.download', $job->id);
            $data['filename'] = $job->result_filename;
        }

        if ($job->status === 'failed') {
            $data['error'] = $job->error_message;
        }

        return response()->json($data);
    }

    public function download(string $id): StreamedResponse
    {
        $job = ExportJob::findOrFail($id);
        abort_unless($job->user_id === auth()->id(), 403);
        abort_unless($job->status === 'completed', 404);

        $absPath = storage_path("app/{$job->result_path}");
        abort_unless(file_exists($absPath), 404);

        $path = $absPath;
        $filename = $job->result_filename;
        $mime = $job->mime_type ?? 'application/octet-stream';

        return response()->streamDownload(function () use ($path): void {
            $stream = fopen($path, 'rb');
            fpassthru($stream);
            fclose($stream);
        }, $filename, ['Content-Type' => $mime]);
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    private function authorizeExport(User $user, string $type, array $params): void
    {
        match ($type) {
            'pds' => $this->authPds($user, $params),
            'form48' => $this->authForm48($user, $params),
            'form48_dept_zip',
            'form48_dept' => $this->authForm48Dept($user, $params),
            'monitoring_matrix' => $this->authMonitoringMatrix($user),
            'leave_card' => $this->authLeaveCard($user, $params),
            'hr_reports' => abort_unless(RoleNormalizer::normalize((string) $user->access_level) === 'hr manager', 403),
        };
    }

    private function authPds(User $user, array $params): void
    {
        abort_unless(isset($params['user_id']) && (int) $params['user_id'] === $user->id, 403);
    }

    private function authForm48(User $user, array $params): void
    {
        $role = strtolower(trim((string) ($user->access_level ?? '')));
        $isAdmin = in_array($role, self::ADMIN_DTR_ROLES, true);

        if ($isAdmin) {
            return;
        }

        if (in_array($role, ['administrative officer', 'department head'], true)) {
            $deptIds = $this->departmentService->resolveAllDepartmentsForUser($user)
                ->pluck('Dept_id')->map(fn ($id) => (int) $id)->all();
            $target = User::find((int) ($params['target_user_id'] ?? 0));
            abort_unless($target && in_array((int) $target->Dept_id, $deptIds, true), 403);

            return;
        }

        // Employee - own record only
        abort_unless(isset($params['target_user_id']) && (int) $params['target_user_id'] === $user->id, 403);
    }

    private function authForm48Dept(User $user, array $params): void
    {
        $role = strtolower(trim((string) ($user->access_level ?? '')));
        $isAdmin = in_array($role, self::ADMIN_DTR_ROLES, true);

        if ($isAdmin) {
            return;
        }

        abort_unless(in_array($role, ['administrative officer', 'department head'], true), 403);

        $deptIds = $this->departmentService->resolveAllDepartmentsForUser($user)
            ->pluck('Dept_id')->map(fn ($id) => (int) $id)->all();
        abort_unless(in_array((int) ($params['dept_id'] ?? 0), $deptIds, true), 403);
    }

    private function authLeaveCard(User $user, array $params): void
    {
        $role = strtolower(trim((string) ($user->access_level ?? '')));
        abort_unless(in_array($role, ['leave manager', 'hr manager'], true), 403);
    }

    private function authMonitoringMatrix(User $user): void
    {
        $role = strtolower(trim((string) ($user->access_level ?? '')));
        abort_unless($role === 'administrative officer', 403);
    }
}
