<?php

namespace App\Exports;

use App\Models\Pds;
use App\Models\User;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PdsExport implements FromArray, WithHeadings, WithStyles, WithEvents
{
    protected User $user;
    protected Pds $pds;

    public function __construct(User $user, Pds $pds)
    {
        $this->user = $user;
        $this->pds = $pds;
    }

    public function array(): array
    {
        $data = [];
        $sectionData = $this->pds->getAllSectionData();

        // Section I: Personal Information
        $data[] = ['I. PERSONAL INFORMATION'];
        $personal = $sectionData['pds-personal-info'] ?? [];
        $data[] = ['Surname', $personal['personal[surname]'] ?? ''];
        $data[] = ['First Name', $personal['personal[first_name]'] ?? ''];
        $data[] = ['Middle Name', $personal['personal[middle_name]'] ?? ''];
        $data[] = ['Name Extension', $personal['personal[name_extension]'] ?? ''];
        $data[] = ['Date of Birth', $personal['personal[birth_date]'] ?? ''];
        $data[] = ['Place of Birth', $personal['personal[birth_place]'] ?? ''];
        $data[] = ['Sex', $personal['personal[sex]'] ?? ''];
        $data[] = ['Civil Status', $personal['personal[civil_status]'] ?? ''];
        $data[] = ['Citizenship', $personal['personal[citizenship]'] ?? ''];
        $data[] = [''];

        // Section II: Family Background
        $data[] = ['II. FAMILY BACKGROUND'];
        $family = $sectionData['pds-family-background'] ?? [];
        $data[] = ['Spouse Name', $family['family[spouse_name]'] ?? 'N/A'];
        $data[] = ['Spouse Occupation', $family['family[spouse_occupation]'] ?? 'N/A'];
        $data[] = ['Father Name', $family['family[father_name]'] ?? ''];
        $data[] = ['Mother Name', $family['family[mother_name]'] ?? ''];
        $data[] = [''];

        // Section III: Education
        $data[] = ['III. EDUCATIONAL BACKGROUND'];
        $education = $sectionData['pds-education'] ?? [];
        $data[] = ['Level', 'School Name', 'Course', 'Graduation Year'];
        $eduLevels = ['elementary', 'secondary', 'vocational', 'college', 'graduate'];
        foreach ($eduLevels as $level) {
            $data[] = [
                ucfirst($level),
                $education["education[{$level}_school]"] ?? '',
                $education["education[{$level}_course]"] ?? '',
                $education["education[{$level}_year]"] ?? '',
            ];
        }
        $data[] = [''];

        // Section IV: Eligibility
        $data[] = ['IV. CIVIL SERVICE ELIGIBILITY'];
        $eligibility = $sectionData['pds-eligibility'] ?? [];
        $data[] = ['Exam/Rating', 'Date Obtained', 'Place Obtained', 'License Number', 'Validity'];

        $eligRows = [];
        foreach ($eligibility as $k => $v) {
            if (preg_match('/^eligibility\[(\d+)\]\[(.+)\]$/', (string) $k, $m)) {
                $idx = (int) $m[1];
                $field = $m[2];
                $eligRows[$idx][$field] = (string) $v;
            }
        }

        // output up to 6 rows
        for ($i = 0; $i < 6; $i++) {
            $row = $eligRows[$i] ?? [];

            $type = $row['type'] ?? $eligibility["eligibility[exam_{$i}]"] ?? '';
            $date = $row['exam_date'] ?? $eligibility["eligibility[exam_{$i}_date]"] ?? '';
            $place = $row['place'] ?? $eligibility["eligibility[exam_{$i}_place]"] ?? '';
            $license = $row['license_no'] ?? $eligibility["eligibility[exam_{$i}_license]"] ?? '';
            $validity = $row['validity'] ?? $eligibility["eligibility[exam_{$i}_validity]"] ?? '';

           
            if ($type === '') {
                $type = $eligibility["eligibility[exam_" . ($i+1) . "]"] ?? $type;
            }
            if ($date === '') {
                $date = $eligibility["eligibility[exam_" . ($i+1) . "_date]"] ?? $date;
            }

            $data[] = [
                $type ?? '',
                $date ?? '',
                $place ?? '',
                $license ?? '',
                $validity ?? '',
            ];
        }
        $data[] = [''];

        // Section V: Work Experience
        $data[] = ['V. WORK EXPERIENCE'];
        $workExp = $sectionData['pds-work-experience'] ?? [];
        $data[] = ['Position', 'Company/Agency', 'From', 'To', 'Status', 'Govt Service (Y/N)'];

        // Normalize saved work keys like work[0][position] into rows
        $workRows = [];
        foreach ($workExp as $k => $v) {
            if (preg_match('/^work\[(\d+)\]\[(.+)\]$/', (string) $k, $m)) {
                $idx = (int) $m[1];
                $field = $m[2];
                $workRows[$idx][$field] = (string) $v;
            }
        }

        // Normalize to_present flags so only one row is treated as PRESENT (choose most recent by 'from' date)
        $presentCandidates = [];
        foreach ($workRows as $idx => $r) {
            $tp = $r['to_present'] ?? ($workExp["work[to_present_{$idx}]"] ?? ($workExp["work[to_present_" . ($idx+1) . "]"] ?? ($workExp["work[to_present]"] ?? false)));
            $toField = $r['to'] ?? ($workExp["work[to_{$idx}]"] ?? ($workExp["work[to_" . ($idx+1) . "]"] ?? ''));
            $tpLower = is_string($tp) ? strtolower($tp) : $tp;
            $isPresentRaw = !empty($tp) && ($tp === '1' || $tp === 'true' || $tp === true || $tpLower === 'present' || $toField === 'PRESENT');
            if ($isPresentRaw) {
                $from = $r['from'] ?? ($workExp["work[from_{$idx}]"] ?? ($workExp["work[from_" . ($idx+1) . "]"] ?? ''));
                $ts = strtotime($from);
                if ($ts === false) {
                    $ts = 0;
                }
                $presentCandidates[$idx] = $ts;
            }
        }

        $selectedPresentIndex = null;
        if (count($presentCandidates) === 1) {
            $keys = array_keys($presentCandidates);
            $selectedPresentIndex = $keys[0];
        } elseif (count($presentCandidates) > 1) {
            arsort($presentCandidates);
            $selectedPresentIndex = (int) key($presentCandidates);
        }

        for ($i = 0; $i < 12; $i++) {
            $row = $workRows[$i] ?? [];

            // Fallback candidates for common key patterns
            $position = $row['position'] ?? ($workExp["work[position_{$i}]"] ?? ($workExp["work[position_" . ($i+1) . "]"] ?? ''));
            $agency = $row['agency'] ?? ($workExp["work[company_{$i}]"] ?? ($workExp["work[agency_" . ($i+1) . "]"] ?? ($workExp["work[company_" . ($i+1) . "]"] ?? '')));
            $from = $row['from'] ?? ($workExp["work[from_{$i}]"] ?? ($workExp["work[from_" . ($i+1) . "]"] ?? ''));
            $toRaw = $row['to'] ?? ($workExp["work[to_{$i}]"] ?? ($workExp["work[to_" . ($i+1) . "]"] ?? ''));
            $status = $row['status'] ?? ($workExp["work[status_{$i}]"] ?? ($workExp["work[status_" . ($i+1) . "]"] ?? ''));
            $isGov = $row['is_government'] ?? ($workExp["work[is_government_{$i}]"] ?? ($workExp["work[is_government_" . ($i+1) . "]"] ?? ($workExp["work[is_government]"] ?? '')));

            // Only skip if ALL of position, from, agency, status, is_government are empty
            $fields = [$position, $from, $agency, $status, $isGov];
            $allEmpty = true;
            foreach ($fields as $f) {
                if (trim((string)$f) !== '') {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                continue;
            }

            $toVal = '';
            // Determine PRESENT using normalized selected index when applicable
            if ($selectedPresentIndex !== null && $selectedPresentIndex === $i) {
                $toVal = 'PRESENT';
            } else {
                $toPresent = $row['to_present'] ?? ($workExp["work[to_present_{$i}]"] ?? ($workExp["work[to_present_" . ($i+1) . "]"] ?? ($workExp["work[to_present]"] ?? false)));
                if (!empty($toPresent) && ($toPresent === '1' || $toPresent === 'true' || $toPresent === true || (is_string($toPresent) && strtolower($toPresent) === 'present'))) {
                    $toVal = 'PRESENT';
                } else {
                    $toVal = $toRaw;
                }
            }

            $data[] = [
                $position ?? '',
                $agency ?? '',
                $from ?? '',
                $toVal ?? '',
                $status ?? '',
                $isGov ?? '',
            ];
        }
        $data[] = [''];

        // Section VI: Voluntary Work
        $data[] = ['VI. VOLUNTARY WORK'];
        $voluntary = $sectionData['pds-voluntary-work'] ?? [];
        $data[] = ['Organization', 'Position', 'From', 'To', 'Hours'];
        for ($i = 1; $i <= 6; $i++) {
            $data[] = [
                $voluntary["voluntary[org_{$i}]"] ?? '',
                $voluntary["voluntary[position_{$i}]"] ?? '',
                $voluntary["voluntary[from_{$i}]"] ?? '',
                $voluntary["voluntary[to_{$i}]"] ?? '',
                $voluntary["voluntary[hours_{$i}]"] ?? '',
            ];
        }
        $data[] = [''];

        // Section VII: Learning and Development
        $data[] = ['VII. LEARNING AND DEVELOPMENT'];
        $learning = $sectionData['pds-learning-dev'] ?? [];
        $data[] = ['Program Title', 'From', 'To', 'Sponsor'];
        for ($i = 1; $i <= 10; $i++) {
            $idx = $i - 1;
            $data[] = [
                $learning["learning[program_{$i}]"] ?? $learning["training[{$idx}][title]"] ?? '',
                $learning["learning[from_{$i}]"] ?? $learning["training[{$idx}][from]"] ?? '',
                $learning["learning[to_{$i}]"] ?? $learning["training[{$idx}][to]"] ?? '',
                $learning["learning[sponsor_{$i}]"] ?? $learning["training[{$idx}][sponsor]"] ?? '',
            ];
        }
        $data[] = [''];

        // Section VIII: Other Information
        $data[] = ['VIII. OTHER INFORMATION'];
        $other = $sectionData['pds-other-info'] ?? [];
        // handle either aggregated key or indexed keys like other[skills][0]
        $implodeSeries = function (array $bucket, string $prefix): string {
            $items = [];
            for ($j = 0; $j < 7; $j++) {
                $value = trim((string) ($bucket[$prefix . '[' . $j . ']'] ?? ''));
                if ($value !== '') {
                    $items[] = $value;
                }
            }
            return implode("\n", $items);
        };

        $skills = $other['other[skills]'] ?? '';
        if ($skills === '') {
            $skills = $implodeSeries($other, 'other[skills]');
        }
        $distinctions = $other['other[distinctions]'] ?? '';
        if ($distinctions === '') {
            $distinctions = $implodeSeries($other, 'other[distinctions]');
        }
        $memberships = $other['other[memberships]'] ?? '';
        if ($memberships === '') {
            $memberships = $implodeSeries($other, 'other[memberships]');
        }

        $data[] = ['Special Skills', $skills];
        $data[] = ['Distinctions', $distinctions];
        $data[] = ['Memberships', $memberships];
        $data[] = [''];

        // Section IX: Additional Questions
        $data[] = ['IX. ADDITIONAL QUESTIONS'];
        $questions = $sectionData['pds-additional-questions'] ?? [];
        $questionLabels = [
            'q1' => 'Have you been a candidate?',
            'q2' => 'Have you been convicted?',
            'q3' => 'Have you been separated?',
            'q4' => 'Have you taken oath?',
            'q5' => 'Are you a natural-born?',
            'q6' => 'Are you a resident?',
            'q7' => 'Do you have pending?',
        ];
        foreach ($questionLabels as $key => $label) {
            $data[] = [
                $label,
                $questions["question[{$key}]"] ?? '',
                $questions["question[{$key}_detail]"] ?? '',
            ];
        }
        $data[] = [''];

        // Section X: References
        $data[] = ['X. REFERENCES'];
        $references = $sectionData['pds-references'] ?? [];
        $data[] = ['Name', 'Address', 'Contact Number'];
        for ($i = 1; $i <= 3; $i++) {
            $data[] = [
                $references["reference[name_{$i}]"] ?? '',
                $references["reference[address_{$i}]"] ?? '',
                $references["reference[contact_{$i}]"] ?? '',
            ];
        }
        $data[] = [''];

        // Section XI: Declaration
        $data[] = ['XI. DECLARATION'];
        $declaration = $sectionData['pds-declaration'] ?? [];
        $data[] = ['GSIS ID', $declaration['declaration[gsis_id]'] ?? ''];
        $data[] = ['PAG-IBIG ID', $declaration['declaration[pagibig_id]'] ?? ''];
        $data[] = ['PHILHEALTH ID', $declaration['declaration[philhealth_id]'] ?? ''];
        $data[] = ['PSN ID', $declaration['declaration[psn_id]'] ?? ''];
        $data[] = ['TIN', $declaration['declaration[tin]'] ?? ''];
        $data[] = ['ID Issue', $declaration['declaration[id_issue]'] ?? ''];
        $data[] = ['Signature Over Printed Name', $declaration['declaration[signature_name]'] ?? ''];
        $data[] = ['Date Accomplished', $declaration['declaration[date_accomplished]'] ?? ''];

        return $data;
    }

    public function headings(): array
    {
        return [
            'CS Form No. 212 - Personal Data Sheet (Revised 2025)',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'size' => 14],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            'A' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true]],
            'B' => ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true]],
        ];
    }

    /**
     * Register events to apply worksheet protection per-employee.
     * Password format: firstname + first letter of lastname (uppercase)
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Build password from user (employee) data
                $first = property_exists($this->user, 'firstname') ? $this->user->firstname : ($this->user->first_name ?? '');
                $last = property_exists($this->user, 'lastname') ? $this->user->lastname : ($this->user->last_name ?? '');
                $password = strtoupper($first . substr((string) $last, 0, 1));

                // Determine used range and explicitly lock all cells
                $highestRow = (int) $sheet->getHighestRow();
                $highestColumn = $sheet->getHighestColumn();
                $range = 'A1:' . $highestColumn . $highestRow;
                $sheet->getStyle($range)->getProtection()->setLocked(true);

                // Also set cell-level locked flag for each cell in the used range to ensure no cells remain unlocked
                $colCount = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
                for ($r = 1; $r <= $highestRow; $r++) {
                    for ($c = 1; $c <= $colCount; $c++) {
                        $cellAddr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;
                        $sheet->getStyle($cellAddr)->getProtection()->setLocked(true);
                    }
                }

                // Apply strict sheet protection options to prevent editing
                $protection = $sheet->getProtection();
                $protection->setPassword($password);
                $protection->setSheet(true);
                // Disallow most user actions in Excel
                $protection->setSort(false);
                $protection->setInsertRows(false);
                $protection->setInsertColumns(false);
                $protection->setFormatCells(false);
                $protection->setFormatColumns(false);
                $protection->setFormatRows(false);
                $protection->setDeleteRows(false);
                $protection->setDeleteColumns(false);
                $protection->setAutoFilter(false);
                $protection->setPivotTables(false);
                $protection->setObjects(false);
                $protection->setScenarios(false);
                // Prevent selecting/unlocking cells to avoid edits
                $protection->setSelectLockedCells(false);
                $protection->setSelectUnlockedCells(false);
            },
        ];
    }
}
