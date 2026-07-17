<?php

namespace App\Services;

use App\Models\HRAuditTrail;
use App\Models\LeaveBalance;
use App\Models\UniformInspection;
use App\Models\UniformInspectionDeduction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UniformInspectionDeductionService
{
    public function __construct(private readonly LeaveLedgerService $leaveLedgerService) {}

    /**
     * Deduct 1 VL day for each of the given employee IDs newly flagged in
     * this inspection. Caller is responsible for de-duplicating employee_id
     * within a single request payload before calling this.
     *
     * @param  array<int, int>  $employeeIds
     * @return array{deducted: array<int, User>, skipped: array<int, User>}
     */
    public function applyForNewEmployees(UniformInspection $inspection, array $employeeIds, User $actor): array
    {
        $deducted = [];
        $skipped = [];

        foreach (array_unique(array_map('intval', $employeeIds)) as $employeeId) {
            $result = $this->deductOne($inspection, $employeeId, $actor);

            if ($result['status'] === UniformInspectionDeduction::STATUS_DEDUCTED) {
                $deducted[] = $result['employee'];
            } elseif ($result['status'] === UniformInspectionDeduction::STATUS_SKIPPED) {
                $skipped[] = $result['employee'];
            }
        }

        return ['deducted' => $deducted, 'skipped' => $skipped];
    }

    /**
     * Reconcile deduction tracking rows against the current set of employee
     * IDs that still have at least one violation row in this inspection,
     * after UniformInspectionDetail rows have already been synced. Employees
     * no longer present get refunded (if deducted) or cleaned up (if
     * skipped); newly-present employees get a fresh deduction attempt.
     *
     * @param  array<int, int>  $currentEmployeeIds
     * @return array{deducted: array<int, User>, skipped: array<int, User>}
     */
    public function reconcile(UniformInspection $inspection, array $currentEmployeeIds, User $actor): array
    {
        $currentEmployeeIds = array_values(array_unique(array_map('intval', $currentEmployeeIds)));

        $trackedRows = UniformInspectionDeduction::where('uniform_inspection_id', $inspection->id)->get();

        foreach ($trackedRows as $row) {
            if (in_array($row->employee_id, $currentEmployeeIds, true)) {
                continue;
            }

            if ($row->status === UniformInspectionDeduction::STATUS_DEDUCTED) {
                $this->refundOne($row, $actor);
            } elseif ($row->status === UniformInspectionDeduction::STATUS_SKIPPED) {
                $row->delete();
            }
        }

        $trackedEmployeeIds = $trackedRows
            ->whereIn('status', [UniformInspectionDeduction::STATUS_DEDUCTED, UniformInspectionDeduction::STATUS_SKIPPED])
            ->pluck('employee_id')
            ->all();

        $newEmployeeIds = array_diff($currentEmployeeIds, $trackedEmployeeIds);

        return $this->applyForNewEmployees($inspection, $newEmployeeIds, $actor);
    }

    /**
     * Refund all currently-'deducted' tracking rows for an inspection. Must
     * be called before UniformInspection::delete(), which cascade-deletes
     * the tracking rows themselves at the DB level.
     */
    public function refundAllForInspection(UniformInspection $inspection, User $actor): void
    {
        UniformInspectionDeduction::where('uniform_inspection_id', $inspection->id)
            ->where('status', UniformInspectionDeduction::STATUS_DEDUCTED)
            ->get()
            ->each(fn (UniformInspectionDeduction $row) => $this->refundOne($row, $actor));
    }

    /**
     * @return array{status: string, employee: ?User}
     */
    private function deductOne(UniformInspection $inspection, int $employeeId, User $actor): array
    {
        return DB::transaction(function () use ($inspection, $employeeId, $actor) {
            $existing = UniformInspectionDeduction::where('uniform_inspection_id', $inspection->id)
                ->where('employee_id', $employeeId)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status !== UniformInspectionDeduction::STATUS_REFUNDED) {
                return ['status' => null, 'employee' => null];
            }

            $employee = User::find($employeeId);
            $balance = LeaveBalance::where('user_id', $employeeId)->lockForUpdate()->first();

            $days = 1.0;
            $before = (float) ($balance->VL ?? 0);

            if (! $balance || $before < $days) {
                if ($existing) {
                    $existing->status = UniformInspectionDeduction::STATUS_SKIPPED;
                    $existing->deducted_days = null;
                    $existing->created_by = $actor->id;
                    $existing->save();
                } else {
                    UniformInspectionDeduction::create([
                        'uniform_inspection_id' => $inspection->id,
                        'employee_id' => $employeeId,
                        'status' => UniformInspectionDeduction::STATUS_SKIPPED,
                        'deducted_days' => null,
                        'created_by' => $actor->id,
                    ]);
                }

                $this->auditBestEffort([
                    'actor_user_id' => $actor->id,
                    'module' => 'uniform_inspection',
                    'action' => 'uniform_inspection_vl_deduction_skipped',
                    'target_type' => 'uniform_inspection',
                    'target_id' => $inspection->id,
                    'details' => [
                        'employee_id' => $employeeId,
                        'reason' => $balance ? 'insufficient_balance' : 'no_leave_balance_record',
                        'balance_before' => $before,
                    ],
                ]);

                return ['status' => UniformInspectionDeduction::STATUS_SKIPPED, 'employee' => $employee];
            }

            $balance->VL = round($before - $days, 3);
            $balance->save();

            if ($existing) {
                $existing->status = UniformInspectionDeduction::STATUS_DEDUCTED;
                $existing->deducted_days = $days;
                $existing->created_by = $actor->id;
                $existing->save();
            } else {
                UniformInspectionDeduction::create([
                    'uniform_inspection_id' => $inspection->id,
                    'employee_id' => $employeeId,
                    'status' => UniformInspectionDeduction::STATUS_DEDUCTED,
                    'deducted_days' => $days,
                    'created_by' => $actor->id,
                ]);
            }

            $this->leaveLedgerService->writeLedgerEntry([
                'user_id' => $employeeId,
                'transaction_date' => now()->toDateString(),
                'transaction_type' => 'UNIFORM_INSPECTION_DEDUCTION',
                'leave_type' => 'VL',
                'debit_vl' => $days,
                'reference_id' => $inspection->id,
                'reference_type' => 'uniform_inspection',
                'remarks' => 'VL deduction - uniform inspection violation (Inspection #'.$inspection->id.')',
                'created_by' => $actor->id,
            ]);

            $this->auditBestEffort([
                'actor_user_id' => $actor->id,
                'module' => 'uniform_inspection',
                'action' => 'uniform_inspection_vl_deducted',
                'target_type' => 'uniform_inspection',
                'target_id' => $inspection->id,
                'details' => [
                    'employee_id' => $employeeId,
                    'deducted_days' => $days,
                    'balance_before' => $before,
                    'balance_after' => (float) $balance->VL,
                ],
            ]);

            return ['status' => UniformInspectionDeduction::STATUS_DEDUCTED, 'employee' => $employee];
        });
    }

    private function refundOne(UniformInspectionDeduction $row, User $actor): void
    {
        DB::transaction(function () use ($row, $actor) {
            $days = (float) ($row->deducted_days ?? 1.0);
            $balance = LeaveBalance::where('user_id', $row->employee_id)->lockForUpdate()->first();

            if ($balance) {
                $before = (float) ($balance->VL ?? 0);
                $balance->VL = round($before + $days, 3);
                $balance->save();
            }

            $row->status = UniformInspectionDeduction::STATUS_REFUNDED;
            $row->save();

            $this->leaveLedgerService->writeLedgerEntry([
                'user_id' => $row->employee_id,
                'transaction_date' => now()->toDateString(),
                'transaction_type' => 'UNIFORM_INSPECTION_REFUND',
                'leave_type' => 'VL',
                'credit_vl' => $days,
                'reference_id' => $row->uniform_inspection_id,
                'reference_type' => 'uniform_inspection',
                'remarks' => 'VL refund - uniform inspection violation removed (Inspection #'.$row->uniform_inspection_id.')',
                'created_by' => $actor->id,
            ]);

            $this->auditBestEffort([
                'actor_user_id' => $actor->id,
                'module' => 'uniform_inspection',
                'action' => 'uniform_inspection_vl_refunded',
                'target_type' => 'uniform_inspection',
                'target_id' => $row->uniform_inspection_id,
                'details' => [
                    'employee_id' => $row->employee_id,
                    'refunded_days' => $days,
                ],
            ]);
        });
    }

    private function auditBestEffort(array $payload): void
    {
        try {
            HRAuditTrail::create($payload);
        } catch (\Exception $e) {
            Log::error('HRAuditTrail write failed for uniform inspection VL action', $payload + ['error' => $e->getMessage()]);
        }
    }
}
