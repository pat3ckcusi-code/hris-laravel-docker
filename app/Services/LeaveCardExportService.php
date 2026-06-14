<?php

namespace App\Services;

use App\Models\LeaveBalance;
use App\Models\LeaveLedger;
use App\Models\User;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveCardExportService
{
    private const TEMPLATE = 'LEAVE CARDS CGC EMPLOYEES.xlsx';

    private const SHEET = 'BLANK';

    /**
     * Build the spreadsheet object without streaming it.
     * Returns [Spreadsheet, filename].
     */
    public function buildSpreadsheet(User $user, int $year, int $month): array
    {
        $templatePath = storage_path('app/templates/'.self::TEMPLATE);
        if (! file_exists($templatePath)) {
            abort(404, 'Leave card template not found.');
        }

        $wb = IOFactory::load($templatePath);
        $ws = $wb->getSheetByName(self::SHEET);

        if (! $ws) {
            abort(500, 'Leave card sheet not found in template.');
        }

        // ── Strip all sheets except BLANK ──────────────────────────────────────
        for ($i = $wb->getSheetCount() - 1; $i >= 0; $i--) {
            if ($wb->getSheet($i)->getTitle() !== self::SHEET) {
                $wb->removeSheetByIndex($i);
            }
        }
        $wb->setActiveSheetIndex(0);

        // ── Rename sheet to employee name ──────────────────────────────────────
        $lastName = strtoupper(trim($user->last_name ?? ''));
        $firstName = strtoupper(trim($user->first_name ?? ''));
        $middleName = strtoupper(trim($user->middle_name ?? ''));
        $sheetTitle = mb_substr("{$lastName}, {$firstName}", 0, 31);
        $ws->setTitle($sheetTitle);

        // ── Employee info ──────────────────────────────────────────────────────
        $fullName = trim("{$lastName}, {$firstName}".($middleName !== '' ? " {$middleName}" : ''));
        $ws->setCellValue('A1', $fullName);
        $ws->setCellValue('K1', $user->date_hired
            ? Carbon::parse($user->date_hired)->format('M d, Y')
            : '');

        // ── Opening balance (balance at end of previous month) ─────────────────
        $monthStart = Carbon::create($year, $month, 1)->toDateString();

        $prior = LeaveLedger::where('user_id', $user->id)
            ->where('transaction_date', '<', $monthStart)
            ->orderByDesc('created_at')
            ->select(['vl_balance_after', 'sl_balance_after'])
            ->first();

        if ($prior) {
            $openVl = (float) $prior->vl_balance_after;
            $openSl = (float) $prior->sl_balance_after;
        } else {
            $lb = LeaveBalance::where('user_id', $user->id)->first();
            $openVl = (float) ($lb?->VL ?? 0);
            $openSl = (float) ($lb?->SL ?? 0);
        }

        // Row 7 — Balance Brought Forward
        $ws->setCellValue('B7', 'BALANCE BROUGHT FWD');
        $ws->setCellValue('C7', 0);
        $ws->setCellValue('D7', 0);
        $ws->setCellValue('E7', $openVl);
        $ws->setCellValue('F7', 0);
        $ws->setCellValue('G7', 0);
        $ws->setCellValue('H7', 0);
        $ws->setCellValue('I7', $openSl);
        $ws->setCellValue('J7', 0);
        $ws->setCellValue('K7', '');

        // ── Ledger entries for the requested month (from row 8) ───────────────
        $entries = LeaveLedger::where('user_id', $user->id)
            ->whereYear('transaction_date', $year)
            ->whereMonth('transaction_date', $month)
            ->orderBy('created_at')
            ->get();

        $row = 8;
        foreach ($entries as $entry) {
            $type = $entry->transaction_type;
            $lt = strtoupper($entry->leave_type ?? '');

            $ws->setCellValue("A{$row}", $entry->transaction_date->format('M d, Y'));
            $ws->setCellValue("B{$row}", $this->resolveParticulars($type, $lt));

            $ws->setCellValue("C{$row}", (float) ($entry->credit_vl ?? 0));
            $ws->setCellValue("D{$row}", $type === 'LEAVE_USED' ? (float) ($entry->debit_vl ?? 0) : 0);
            $ws->setCellValue("E{$row}", (float) $entry->vl_balance_after); // override formula
            $ws->setCellValue("F{$row}", $type === 'LWOP_DEDUCTION' ? (float) ($entry->debit_vl ?? 0) : 0);
            $ws->setCellValue("G{$row}", (float) ($entry->credit_sl ?? 0)); // override =C formula
            $ws->setCellValue("H{$row}", $type === 'LEAVE_USED' ? (float) ($entry->debit_sl ?? 0) : 0);
            $ws->setCellValue("I{$row}", (float) $entry->sl_balance_after); // override formula
            $ws->setCellValue("J{$row}", $type === 'LWOP_DEDUCTION' ? (float) ($entry->debit_sl ?? 0) : 0);
            $ws->setCellValue("K{$row}", $entry->remarks ?? '');

            $row++;
        }

        $this->protectSheets($wb, $user);

        $filename = sprintf(
            'LeaveCard_%s_%04d_%02d.xlsx',
            $user->EmpNo ?? $user->id,
            $year,
            $month
        );

        return [$wb, $filename];
    }

    public function generateExcelResponse(User $user, int $year, int $month): StreamedResponse
    {
        [$wb, $filename] = $this->buildSpreadsheet($user, $year, $month);

        return response()->streamDownload(
            function () use ($wb): void {
                $writer = IOFactory::createWriter($wb, 'Xlsx');
                $writer->save('php://output');
                $wb->disconnectWorksheets();
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    private function resolveParticulars(string $type, string $leaveType): string
    {
        return match ($type) {
            'CREDIT_EARNED' => 'EARNED',
            'CREDIT_EARNED_WOP' => 'EARNED (WOP)',
            'LEAVE_USED' => match (true) {
                str_contains($leaveType, 'VL') => 'VL',
                str_contains($leaveType, 'SL') => 'SL',
                default => $leaveType,
            },
            'LWOP_DEDUCTION' => str_contains($leaveType, 'VL') ? 'VL WOP' : 'SL WOP',
            'LEAVE_CANCELLED' => 'CANCELLED',
            'MANUAL_ADJUSTMENT' => 'ADJUSTMENT',
            'OPENING_BALANCE' => 'OPENING BALANCE',
            'MONETIZED' => 'MONETIZED',
            'TERMINAL_LEAVE' => 'TERMINAL LEAVE',
            'TRANSFER_IN' => 'TRANSFER IN',
            'TRANSFER_OUT' => 'TRANSFER OUT',
            'COMMUTED' => 'COMMUTED',
            default => '',
        };
    }

    private function protectSheets(Spreadsheet $wb, User $user): void
    {
        $password = strtoupper(
            ($user->first_name ?? '').
            substr((string) ($user->last_name ?? ''), 0, 1)
        );

        foreach ($wb->getAllSheets() as $sheet) {
            $p = $sheet->getProtection();
            $p->setSheet(true);
            $p->setPassword($password);
            $p->setSort(false);
            $p->setInsertRows(false);
            $p->setInsertColumns(false);
            $p->setFormatCells(false);
            $p->setFormatColumns(false);
            $p->setFormatRows(false);
            $p->setDeleteRows(false);
            $p->setDeleteColumns(false);
        }
    }
}
