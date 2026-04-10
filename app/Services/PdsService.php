<?php

namespace App\Services;

use App\Models\Pds;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class PdsService
{
    public function getOrCreatePds(User $user): Pds
    {
        return Pds::firstOrCreate(
            ['user_id' => $user->id],
            ['section_data' => []]
        );
    }

    public function exportToExcel(User $user): Spreadsheet
    {
        $pds = $this->getOrCreatePds($user);
        $pdsData = $pds->getAllSectionData();

        $templatePath = storage_path('app/templates/PDS.xlsx');
        if (!is_file($templatePath)) {
            throw new \RuntimeException('PDS template file is missing.');
        }

        $spreadsheet = IOFactory::load($templatePath);
        $this->removeInvalidDefinedNames($spreadsheet);
        $this->removeExtraSheets($spreadsheet, ['C1', 'C2', 'C3', 'C4']);
        $this->fillCscPdsTemplate($spreadsheet, $user, $pdsData);
        $this->protectAllSheets($spreadsheet, $user);

        return $spreadsheet;
    }

    /**
     * @param  array<string, array<string, mixed>>  $pdsData
     */
    private function fillCscPdsTemplate(Spreadsheet $spreadsheet, User $user, array $pdsData): void
    {               
        $sheet = $spreadsheet->getActiveSheet();
        $family = $pdsData['pds-family-background'] ?? [];
        // Continue with PERSONAL INFO and other mappings
        $personal = $pdsData['pds-personal-info'] ?? [];
        // Personal Info
        $sheet->setCellValue('D10', $personal['personal[surname]'] ?? ($user->last_name ?? ''));
        $sheet->setCellValue('D11', $personal['personal[first_name]'] ?? ($user->first_name ?? ''));
        $sheet->setCellValue('D12', $personal['personal[middle_name]'] ?? ($user->middle_name ?? ''));
        $sheet->setCellValue('X11', 'NAME EXTENSION (JR., SR): ' . ($personal['personal[name_extension]'] ?? ''));
        $sheet->setCellValue('D13', !empty($personal['personal[birth_date]']) ? date('d/m/Y', strtotime($personal['personal[birth_date]'])) : '');
        $sheet->setCellValue('D17', $personal['personal[birth_place]'] ?? '');
        $sheet->setCellValue('D30', $personal['personal[height]'] ?? '');
        $sheet->setCellValue('D32', $personal['personal[weight]'] ?? '');
        $sheet->setCellValue('D34', $personal['personal[blood_type]'] ?? '');
        $sheet->setCellValue('D36', $personal['personal[gsis_no]'] ?? '');
        $sheet->setCellValue('D38', $personal['personal[pagibig_no]'] ?? '');
        $sheet->setCellValue('D40', $personal['personal[philhealth_no]'] ?? '');
        $sheet->setCellValue('D41', $personal['personal[psn_no]'] ?? '');
        $sheet->setCellValue('D42', $personal['personal[tin_no]'] ?? '');
        $sheet->setCellValue('D43', $personal['personal[agency_employee_no]'] ?? ($user->EmpNo ?? ''));
        $sheet->setCellValue('Q43', $personal['personal[email]'] ?? ($user->email ?? ''));
        $sheet->setCellValue('Q42', $personal['personal[mobile]'] ?? '');
        $sheet->setCellValue('Q41', $personal['personal[telephone]'] ?? '');
        $sheet->setCellValue('Q40', $personal['permanent[zip]'] ?? '');
        $sheet->setCellValue('Q38', $personal['permanent[city]'] ?? '');
        $sheet->setCellValue('X38', $personal['permanent[province]'] ?? '');
        $sheet->setCellValue('X36', $personal['permanent[barangay]'] ?? '');
        $sheet->setCellValue('Q36', $personal['permanent[subdivision]'] ?? '');
        $sheet->setCellValue('Q34', $personal['permanent[house]'] ?? '');
        $sheet->setCellValue('X34', $personal['permanent[street]'] ?? '');
        $sheet->setCellValue('Q32', $personal['residential[zip]'] ?? '');
        $sheet->setCellValue('Q30', $personal['residential[city]'] ?? '');
        $sheet->setCellValue('X30', $personal['residential[province]'] ?? '');
        $sheet->setCellValue('X27', $personal['residential[barangay]'] ?? '');
        $sheet->setCellValue('Q27', $personal['residential[subdivision]'] ?? '');
        $sheet->setCellValue('Q23', $personal['residential[house]'] ?? '');
        $sheet->setCellValue('X23', $personal['residential[street]'] ?? '');

        // Gender checkbox: E19 (Male), K19 (Female)
        $gender = strtoupper(trim($personal['personal[sex]'] ?? ''));
        $sheet->setCellValue('E19', '');
        $sheet->setCellValue('K19', '');
        if ($gender === 'MALE') {
            $sheet->setCellValue('E19', '✓');
        } elseif ($gender === 'FEMALE') {
            $sheet->setCellValue('K19', '✓');
        }

        // Civil status checkbox: E23 (Single), K23 (Married), E25 (Widowed), K25 (Separated), E27 (Others)
        $civil = strtoupper(trim($personal['personal[civil_status]'] ?? ''));
        $sheet->setCellValue('E23', ''); $sheet->setCellValue('K23', ''); $sheet->setCellValue('E25', ''); $sheet->setCellValue('K25', ''); $sheet->setCellValue('E27', '');
        if ($civil === 'SINGLE') {
            $sheet->setCellValue('E23', '✓');
        } elseif ($civil === 'MARRIED') {
            $sheet->setCellValue('K23', '✓');
        } elseif ($civil === 'WIDOWED' || $civil === 'WIDOWER' || $civil === 'WIDOW') {
            $sheet->setCellValue('E25', '✓');
        } elseif ($civil === 'SEPARATED') {
            $sheet->setCellValue('K25', '✓');
        } elseif ($civil === 'OTHERS' || $civil === 'OTHER') {
            $sheet->setCellValue('E27', '✓');
        }

        // Citizenship checkbox: S14 (Filipino), V14 (Dual), W16 (By birth), Z16 (By naturalization)
        $cit = strtoupper(trim($personal['personal[citizenship]'] ?? ''));
        $sheet->setCellValue('S14', ''); $sheet->setCellValue('V14', ''); $sheet->setCellValue('W16', ''); $sheet->setCellValue('Z16', '');
        if ($cit === 'FILIPINO') {
            $sheet->setCellValue('S14', '✓');
        } elseif ($cit === 'DUAL CITIZENSHIP' || $cit === 'DUAL') {
            $sheet->setCellValue('V14', '✓');
        } elseif ($cit === 'BY BIRTH') {
            $sheet->setCellValue('W16', '✓');
        } elseif ($cit === 'BY NATURALIZATION') {
            $sheet->setCellValue('Z16', '✓');
        }
        // Spouse
        $sheet->setCellValue('D45', $family['family[spouse_surname]'] ?? '');
        $sheet->setCellValue('D46', $family['family[spouse_first_name]'] ?? '');
        $sheet->setCellValue('O46', 'NAME EXTENSION (JR., SR): ' . ($family['family[spouse_name_extension]'] ?? ''));
        $sheet->setCellValue('D47', $family['family[spouse_middle_name]'] ?? '');
        $sheet->setCellValue('D48', $family['family[spouse_occupation]'] ?? '');
        $sheet->setCellValue('D49', $family['family[spouse_employer]'] ?? '');
        $sheet->setCellValue('D50', $family['family[spouse_business_address]'] ?? '');
        $sheet->setCellValue('D51', $family['family[spouse_tel]'] ?? '');
        // Father
        $sheet->setCellValue('D52', $family['family[father_surname]'] ?? '');
        $sheet->setCellValue('D53', $family['family[father_first_name]'] ?? '');
        $sheet->setCellValue('O53', 'NAME EXTENSION (JR., SR): ' . ($family['family[father_name_extension]'] ?? ''));
        $sheet->setCellValue('D54', $family['family[father_middle_name]'] ?? '');
        // Mother
        $sheet->setCellValue('D56', $family['family[mother_surname]'] ?? '');
        $sheet->setCellValue('D57', $family['family[mother_first_name]'] ?? '');
        $sheet->setCellValue('D58', $family['family[mother_middle_name]'] ?? '');

        // Children (first 12)
        for ($i = 0, $rowIdx = 46; $i < 12 && $rowIdx <= 57; $i++, $rowIdx++) {
            $childName = $family['children[' . $i . '][name]'] ?? '';
            $childBirth = !empty($family['children['.$i.'][birth_date]']) ? date('d/m/Y', strtotime($family['children['.$i.'][birth_date]'])) : ''; 
            $sheet->setCellValue("Q{$rowIdx}", $childName);
            $sheet->setCellValue("Y{$rowIdx}", $childBirth);
        }
        // EDUCATIONAL BACKGROUND
        $education = $pdsData['pds-education'] ?? [];
        $eduMap = [
            0 => [
                'school' => 'D63',
                'course' => 'O63', // O,P,Q merged
                'from' => 'R63',   // R,S,T merged
                'to' => 'U63',     // not merged
                'units' => 'V63',  // V,W,X merged
                'year_graduated' => 'Y63', // Y,Z,AA,AB merged
                'honors' => 'AC63' // not merged
            ],
            1 => [
                'school' => 'D64',
                'course' => 'O64',
                'from' => 'R64',
                'to' => 'U64',
                'units' => 'V64',
                'year_graduated' => 'Y64',
                'honors' => 'AC64'
            ],
            2 => [
                'school' => 'D65',
                'course' => 'O65',
                'from' => 'R65',
                'to' => 'U65',
                'units' => 'V65',
                'year_graduated' => 'Y65',
                'honors' => 'AC65'
            ],
            3 => [
                'school' => 'D66',
                'course' => 'O66',
                'from' => 'R66',
                'to' => 'U66',
                'units' => 'V66',
                'year_graduated' => 'Y66',
                'honors' => 'AC66'
            ],
            4 => [
                'school' => 'D67',
                'course' => 'O67',
                'from' => 'R67',
                'to' => 'U67',
                'units' => 'V67',
                'year_graduated' => 'Y67',
                'honors' => 'AC67'
            ],
        ];

        $rows = [];
        foreach ($education as $key => $val) {
            if (preg_match('/^education\[(\d+)\]\[(.+)\]$/', (string) $key, $m)) {
                $idx = (int) $m[1];
                $field = $m[2];
                $rows[$idx][$field] = (string) $val;
            }
        }

        $defaultLevels = [
            0 => 'Elementary',
            1 => 'Secondary',
            2 => 'Vocational / Trade Course',
            3 => 'College',
            4 => 'Graduate Studies',
        ];

        $groups = [];
        foreach ($rows as $idx => $r) {
            $level = $r['level'] ?? ($defaultLevels[$idx] ?? 'Other');
            $groups[$level][] = $r;
        }

        $baseRows = [0 => 63, 1 => 64, 2 => 65, 3 => 66, 4 => 67];
        $cols = [
            'school' => 'D',
            'course' => 'O',
            'from' => 'R',
            'to' => 'U',
            'units' => 'V',
            'year_graduated' => 'Y',
            'honors' => 'AC',
        ];

        $lastEducationRow = 0;
        $rowOffset = 0;

        for ($i = 0; $i <= 4; $i++) {
            $levelName = $defaultLevels[$i];

            $matched = [];
            foreach ($groups as $gLevel => $gRows) {
                $low = strtolower($gLevel);
                if (str_contains($low, strtolower(strtok($levelName, ' '))) ||
                    ($i === 2 && (str_contains($low, 'vocational') || str_contains($low, 'trade')))
                ) {
                    $matched = array_merge($matched, $gRows);
                }
            }

            if (empty($matched) && isset($rows[$i])) {
                $matched[] = $rows[$i];
            }

            $count = count($matched);
            $actualBaseRow = $baseRows[$i] + $rowOffset;

            if ($count <= 0) {
                foreach ($cols as $field => $col) {
                    $sheet->setCellValue($col . $actualBaseRow, 'N/A');
                }
                $this->safeMerge($sheet, 'D' . $actualBaseRow . ':N' . $actualBaseRow);
                $this->safeMerge($sheet, 'O' . $actualBaseRow . ':Q' . $actualBaseRow);
                $this->safeMerge($sheet, 'R' . $actualBaseRow . ':T' . $actualBaseRow);
                $this->safeMerge($sheet, 'V' . $actualBaseRow . ':X' . $actualBaseRow);
                $this->safeMerge($sheet, 'Y' . $actualBaseRow . ':AB' . $actualBaseRow);
                $lastEducationRow = max($lastEducationRow, $actualBaseRow);
                continue;
            }

            if ($count > 1) {
                $sheet->insertNewRowBefore($actualBaseRow + 1, $count - 1);
                $rowOffset += $count - 1;
            }

            for ($k = 0; $k < $count; $k++) {
                $rowNum = $actualBaseRow + $k;
                $mRow = $matched[$k];

                foreach ($cols as $field => $col) {
                    $v = trim((string) ($mRow[$field] ?? ''));
                    $valueToSet = $v !== '' ? $v : 'N/A';
                    $sheet->setCellValue($col . $rowNum, $valueToSet);
                }

                $this->safeMerge($sheet, 'D' . $rowNum . ':N' . $rowNum);
                $this->safeMerge($sheet, 'O' . $rowNum . ':Q' . $rowNum);
                $this->safeMerge($sheet, 'R' . $rowNum . ':T' . $rowNum);
                $this->safeMerge($sheet, 'V' . $rowNum . ':X' . $rowNum);
                $this->safeMerge($sheet, 'Y' . $rowNum . ':AB' . $rowNum);
                $lastEducationRow = max($lastEducationRow, $rowNum);
            }

            if ($count > 1 && ($i === 3 || $i === 4)) {
                $start = $actualBaseRow;
                $end = $actualBaseRow + $count - 1;
                $this->safeMergePreserve($sheet, 'A' . $start . ':C' . $end);
            }
        }

        // After finishing the education rows, place date_accomplished two rows after last education row
        $declaration = $pdsData['pds-declaration'] ?? [];
        $date_accomplished = $declaration['declaration[date_accomplished]'] ?? ($declaration['date_accomplished'] ?? '');
        if ($lastEducationRow > 0) {
            $targetRow = $lastEducationRow + 2;
            $sheet->setCellValue('X' . $targetRow, $date_accomplished ? date('d/m/Y', strtotime($date_accomplished)) : '');
        }
        
        
        try {
            $sheetC2 = $spreadsheet->getSheetByName('C2');
        } catch (\Throwable $e) {
            $sheetC2 = $spreadsheet->getActiveSheet();
        }

        $row = 1;

        // Section IV: Eligibility
        $rowsIV = [];
        $eligibility = $pdsData['pds-eligibility'] ?? [];

        foreach ($eligibility as $k => $v) {
            if (preg_match('/^eligibility\[(\d+)\]\[(.+)\]$/', (string) $k, $m)) {
                $idx = (int) $m[1];
                $field = $m[2];
                $rowsIV[$idx][$field] = (string) $v;
            }
            if (preg_match('/^eligibility\[exam_(\d+)\]$/', (string) $k, $m)) {
                $idx = (int) $m[1] - 1;
                $rowsIV[$idx]['Career'] = (string) $v;
            }
            if (preg_match('/^eligibility\[exam_(\d+)_date\]$/', (string) $k, $m)) {
                $idx = (int) $m[1] - 1;
                $rowsIV[$idx]['Date'] = (string) $v;
            }
            if (preg_match('/^eligibility\[exam_(\d+)_place\]$/', (string) $k, $m)) {
                $idx = (int) $m[1] - 1;
                $rowsIV[$idx]['Place'] = (string) $v;
            }
            if (preg_match('/^eligibility\[exam_(\d+)_license\]$/', (string) $k, $m)) {
                $idx = (int) $m[1] - 1;
                $rowsIV[$idx]['LiNum'] = (string) $v;
            }
        }

            $c = 0;
            foreach ($rowsIV as $rowiv) {
                $Letnum = 5 + $c;
                if ($Letnum < 12) {
                    $sheetC2->setCellValue('A' . $Letnum, $rowiv['Career'] ?? ($rowiv['type'] ?? ''));
                    $sheetC2->setCellValue('F' . $Letnum, $rowiv['Rating'] ?? ($rowiv['rating'] ?? ''));
                    $sheetC2->setCellValue('I' . $Letnum, $rowiv['Place'] ?? ($rowiv['place'] ?? ''));
                    $sheetC2->setCellValue('J' . $Letnum, $rowiv['LiNum'] ?? ($rowiv['license_no'] ?? ''));

                    $examDateRaw = $rowiv['Date'] ?? ($rowiv['exam_date'] ?? '');
                    $examDate = (!empty($examDateRaw) && $examDateRaw !== '0000-00-00') ? date('d/m/Y', strtotime($examDateRaw)) : 'N/A';
                    $sheetC2->setCellValue('G' . $Letnum, $examDate);

                    $liDateRaw = $rowiv['LiDate'] ?? ($rowiv['license_date'] ?? '');
                    $liDate = (!empty($liDateRaw) && $liDateRaw !== '0000-00-00') ? date('d/m/Y', strtotime($liDateRaw)) : 'N/A';
                    $sheetC2->setCellValue('K' . $Letnum, $liDate);

                    $c++;
                }
            }

            // Section V: Work Experience
            $rowsV = [];
            $workRaw = $pdsData['pds-work-experience'] ?? [];
            foreach ($workRaw as $k => $v) {
                if (preg_match('/^work\[(\d+)\]\[(.+)\]$/', (string) $k, $m)) {
                    $idx = (int) $m[1];
                    $field = $m[2];
                    $rowsV[$idx][$field] = (string) $v;
                }
            }

            foreach ($workRaw as $k => $v) {
                if (preg_match('/^work\\[(position|company|from|to|status|is_government|sg|agency|to_present)_(\\d+)\\]$/', (string) $k, $m)) {
                    $field = $m[1];
                    $idx = (int) $m[2] - 1;
                    $map = ['company' => 'agency', 'sg' => 'sg', 'agency' => 'agency', 'to_present' => 'to_present'];
                    $rowsV[$idx][$map[$field] ?? $field] = (string) $v;
                }
            }

            foreach ($rowsV as $idx => $r) {
                $fields = ['position', 'from', 'agency', 'status', 'is_government'];
                $allEmpty = true;
                foreach ($fields as $f) {
                    if (isset($r[$f]) && trim((string) $r[$f]) !== '') {
                        $allEmpty = false;
                        break;
                    }
                }
                if ($allEmpty) {
                    unset($rowsV[$idx]);
                }
            }

            $presentIndexes = [];
            foreach ($rowsV as $idx => $r) {
                $tp = $r['to_present'] ?? ($r['toPresent'] ?? ($r['to_present_flag'] ?? false));
                if ($tp === '1' || $tp === 1 || $tp === true || $tp === 'true') {
                    $presentIndexes[] = $idx;
                }
            }
            if (count($presentIndexes) > 1) {
                $keep = array_shift($presentIndexes);
                foreach ($presentIndexes as $badIdx) {
                    $rowsV[$badIdx]['to_present'] = '0';
                }
            }

            $ongoingRows = [];
            $finishedRows = [];
            foreach ($rowsV as $row) {
                $toPresentFlag = !empty($row['to_present']) && in_array((string) $row['to_present'], ['1', 'true'], true);
                $indateTo = $row['to'] ?? ($row['IndateTo'] ?? '');
                if ($toPresentFlag || empty($indateTo) || $indateTo === '0000-00-00' || $indateTo === '0000-00-00 00:00:00') {
                    $ongoingRows[] = $row;
                } else {
                    $finishedRows[] = $row;
                }
            }

            if (count($ongoingRows) > 1) {
                usort($ongoingRows, function ($a, $b) {
                    $fa = $a['from'] ?? ($a['IndateFrom'] ?? '');
                    $fb = $b['from'] ?? ($b['IndateFrom'] ?? '');
                    $ta = !empty($fa) && $fa !== '0000-00-00' ? strtotime($fa) : 0;
                    $tb = !empty($fb) && $fb !== '0000-00-00' ? strtotime($fb) : 0;
                    return $tb <=> $ta;
                });

                $keep = array_shift($ongoingRows);
                foreach ($ongoingRows as $other) {
                    $finishedRows[] = $other;
                }
                $ongoingRows = [$keep];
            }

            usort($finishedRows, function ($a, $b) {
                $ta = (!empty($a['to']) && $a['to'] !== '0000-00-00') ? strtotime($a['to']) : 0;
                $tb = (!empty($b['to']) && $b['to'] !== '0000-00-00') ? strtotime($b['to']) : 0;
                return $tb - $ta;
            });

            $sortedRows = array_merge($ongoingRows, $finishedRows);

            $d = 0;
            foreach ($sortedRows as $rowv) {
                $NumExp = 18 + $d;
                if ($NumExp < 46) {
                    $indateFromRaw = $rowv['from'] ?? ($rowv['IndateFrom'] ?? '');
                    $indateFrom = (!empty($indateFromRaw) && $indateFromRaw !== '0000-00-00') ? date('d/m/Y', strtotime($indateFromRaw)) : '';

                    $indateToRaw = $rowv['to'] ?? ($rowv['IndateTo'] ?? '');
                    $indateTo = (empty($indateToRaw) || $indateToRaw === '0000-00-00' || $indateToRaw === '0000-00-00 00:00:00') ? 'PRESENT' : date('d/m/Y', strtotime($indateToRaw));

                    $sheetC2->setCellValue('A' . $NumExp, $indateFrom);
                    $sheetC2->setCellValue('C' . $NumExp, $indateTo);
                    $sheetC2->setCellValue('D' . $NumExp, $rowv['position'] ?? ($rowv['Position'] ?? ''));
                    $sheetC2->setCellValue('G' . $NumExp, $rowv['agency'] ?? ($rowv['Dept'] ?? ($rowv['company'] ?? '')));
                    $sheetC2->setCellValue('J' . $NumExp, $rowv['status'] ?? ($rowv['Status'] ?? ''));
                    $sheetC2->setCellValue('K' . $NumExp, $rowv['is_government'] ?? ($rowv['GovService'] ?? ''));

                    $d++;
                }
            }

                // Sheet C3: Learning & Development, Other Information
                $sheetC3 = $spreadsheet->getSheetByName('C3');
                if ($sheetC3) {
                    // Learning & Development (VII)
                    $learning = $pdsData['pds-learning-dev'] ?? [];
                    $ldRows = [];

                    // Voluntary Work (Section VI) which starts at A7
                    $voluntary = $pdsData['pds-voluntary-work'] ?? [];
                    $volRows = [];
                    if (is_array($voluntary)) {
                        foreach ($voluntary as $k => $v) {
                            if (is_int($k) || ctype_digit((string)$k)) {
                                $idx = (int)$k;
                                if (is_array($v)) {
                                    foreach ($v as $field => $val) {
                                        $volRows[$idx][$field] = $val;
                                    }
                                }
                                continue;
                            }

                            if (preg_match('/^voluntary\[(\d+)\]\[(.+)\]$/', (string)$k, $m)) {
                                $idx = (int)$m[1];
                                $field = $m[2];
                                $volRows[$idx][$field] = $v;
                                continue;
                            }

                            if (preg_match('/^voluntary\[(.+?)_(\d+)\]$/', (string)$k, $m)) {
                                $field = $m[1];
                                $idx = (int)$m[2] - 1;
                                $volRows[$idx][$field] = $v;
                                continue;
                            }
                        }
                    }

                    // Write voluntary rows starting at A7 (Section VI)
                    if (!empty($volRows)) {
                        $volStart = 6;
                        foreach ($volRows as $idx => $vr) {
                            $r = $volStart + (int) $idx;
                            $sheetC3->setCellValue('A' . $r, $vr['organization'] ?? ($vr['org'] ?? ''));
                            $sheetC3->setCellValue('H' . $r, $vr['position'] ?? '');
                            $sheetC3->setCellValue('E' . $r, $vr['from'] ?? '');
                            $sheetC3->setCellValue('F' . $r, $vr['to'] ?? '');
                            $sheetC3->setCellValue('G' . $r, $vr['hours'] ?? '');
                        }
                    }

                    // If learning is a nested numeric array like [0 => ['from'=>'..', 'to'=>'..'], ...]
                    if (is_array($learning)) {
                        foreach ($learning as $k => $v) {
                            // numeric-indexed nested arrays (e.g. 0 => ['title' => '...'])
                            if (is_int($k) || ctype_digit((string)$k)) {
                                $idx = (int)$k;
                                if (is_array($v)) {
                                    foreach ($v as $field => $val) {
                                        $ldRows[$idx][$field] = $val;
                                    }
                                }
                                continue;
                            }

                            if (preg_match('/^(?:learning|training)\[(\d+)\]\[(.+)\]$/', (string)$k, $m)) {
                                $idx = (int)$m[1];
                                $field = $m[2];
                                $ldRows[$idx][$field] = $v;
                                continue;
                            }

                            if (preg_match('/^learning\[(.+?)_(\d+)\]$/', (string)$k, $m)) {
                                $field = $m[1];
                                $idx = (int)$m[2] - 1; 
                                $ldRows[$idx][$field] = $v;
                                continue;
                            }
                        }
                    }
                    
                    if (!empty($ldRows)) {
                        // Sort learning rows so the most recent trainings appear first.
                        usort($ldRows, function ($a, $b) {
                            $aTo = $a['to'] ?? $a['To'] ?? '';
                            $bTo = $b['to'] ?? $b['To'] ?? '';
                            $aTs = $this->parsePdsDateToTimestamp($aTo);
                            $bTs = $this->parsePdsDateToTimestamp($bTo);
                            if ($aTs === $bTs) {
                                $aFrom = $a['from'] ?? $a['From'] ?? '';
                                $bFrom = $b['from'] ?? $b['From'] ?? '';
                                $aFs = $this->parsePdsDateToTimestamp($aFrom);
                                $bFs = $this->parsePdsDateToTimestamp($bFrom);
                                return $bFs <=> $aFs;
                            }

                            return $bTs <=> $aTs;
                        });
                        // Learning section starts at A18 in the template to avoid overlapping Voluntary Work
                        $startRow = 18;
                        foreach ($ldRows as $idx => $lr) {
                            $rowNum = $startRow + (int) $idx;
                            $sheetC3->setCellValue('A' . $rowNum, $lr['title'] ?? ($lr['program'] ?? ''));
                            $sheetC3->setCellValue('E' . $rowNum, $lr['from'] ?? '');
                            $sheetC3->setCellValue('F' . $rowNum, $lr['to'] ?? '');
                            $sheetC3->setCellValue('G' . $rowNum, $lr['hours'] ?? '');
                            $sheetC3->setCellValue('H' . $rowNum, $lr['type'] ?? '');
                            $sheetC3->setCellValue('I' . $rowNum, $lr['sponsor'] ?? '');
                        }
                    }

                    // Other Information (VIII)
                    $other = $pdsData['pds-other-info'] ?? [];
                    $skills = $this->implodeSeries($other, 'other[skills]');
                    $distinctions = $this->implodeSeries($other, 'other[distinctions]');
                    $memberships = $this->implodeSeries($other, 'other[memberships]');
                    $sheetC3->setCellValue('A42', $skills);
                    $sheetC3->setCellValue('C42', $distinctions);
                    $sheetC3->setCellValue('I42', $memberships);
                }

                // Sheet C4: Questions, References, Government ID
                $sheetC4 = $spreadsheet->getSheetByName('C4');
                if ($sheetC4) {
                    // Questions (IX) - Use provided mapping for all questions and sub-questions
                    $questions = $pdsData['pds-additional-questions'] ?? [];
                    $questionMap = [
                        34 => [
                            'a' => ['no' => 'K6', 'yes' => 'H6', 'details' => 'I11'],
                            'b' => ['no' => 'K8', 'yes' => 'H8', 'details' => 'I11'],
                        ],
                        35 => [
                            'a' => ['no' => 'K14', 'yes' => 'H14', 'details' => 'I16'],
                            'b' => ['no' => 'K19', 'yes' => 'H19', 'details' => null],
                        ],
                        36 => ['no' => 'K25', 'yes' => 'H25', 'details' => 'I27'],
                        37 => ['no' => 'K30', 'yes' => 'H30', 'details' => 'I32'],
                        38 => [
                            'a' => ['no' => 'K35', 'yes' => 'H35', 'details' => 'L36'],
                            'b' => ['no' => 'K38', 'yes' => 'H38', 'details' => 'L39'],
                        ],
                        39 => ['no' => 'K42', 'yes' => 'H42', 'details' => 'I44', 'country' => null],
                        40 => [
                            'a' => ['no' => 'K48', 'yes' => 'H48', 'details' => 'M49'],
                            'b' => ['no' => 'K50', 'yes' => 'H50', 'id' => 'M51'],
                            'c' => ['no' => 'K52', 'yes' => 'H52', 'id' => 'M53'],
                        ],
                    ];

                    foreach ($questionMap as $qNum => $qDef) {
                        // If this is a sub-question array (a, b, c)
                        if (is_array($qDef) && isset($qDef['a']) && is_array($qDef['a'])) {
                            foreach ($qDef as $sub => $map) {
                                $ansKey = "questions[{$qNum}][{$sub}][answer]";
                                $detailsKey = "questions[{$qNum}][{$sub}][details]";
                                $idKey = "questions[{$qNum}][{$sub}][id]";
                                if (isset($questions[$ansKey])) {
                                    $ans = strtoupper(trim($questions[$ansKey]));
                                    if ($ans === 'NO' && !empty($map['no'])) {
                                        $sheetC4->setCellValue($map['no'], '✓');
                                    } elseif ($ans === 'YES' && !empty($map['yes'])) {
                                        $sheetC4->setCellValue($map['yes'], '✓');
                                    }
                                }
                                if (!empty($map['details']) && isset($questions[$detailsKey]) && $questions[$detailsKey] !== '') {
                                    $sheetC4->setCellValue($map['details'], $questions[$detailsKey]);
                                }
                                if (!empty($map['id']) && isset($questions[$idKey]) && $questions[$idKey] !== '') {
                                    $sheetC4->setCellValue($map['id'], $questions[$idKey]);
                                }
                            }
                        } else {
                            // Single question
                            $ansKey = "questions[{$qNum}][answer]";
                            $detailsKey = "questions[{$qNum}][details]";
                            $countryKey = "questions[{$qNum}][country]";
                            if (isset($questions[$ansKey])) {
                                $ans = strtoupper(trim($questions[$ansKey]));
                                if ($ans === 'NO' && !empty($qDef['no'])) {
                                    $sheetC4->setCellValue($qDef['no'], '✓');
                                } elseif ($ans === 'YES' && !empty($qDef['yes'])) {
                                    $sheetC4->setCellValue($qDef['yes'], '✓');
                                }
                            }
                            if (!empty($qDef['details']) && isset($questions[$detailsKey]) && $questions[$detailsKey] !== '') {
                                $sheetC4->setCellValue($qDef['details'], $questions[$detailsKey]);
                            }
                        }
                    }

                    // References (X)
                    $references = $pdsData['pds-references'] ?? [];
                    if (!empty($references)) {
                        for ($i = 0; $i <= 2; $i++) {
                            $name = $references["references[{$i}][name]"] ?? ($references[$i]['name'] ?? null);
                            $address = $references["references[{$i}][address]"] ?? ($references[$i]['address'] ?? null);
                            $contact = $references["references[{$i}][tel]"] ?? ($references[$i]['tel'] ?? ($references[$i]['contact'] ?? null));
                            if ($name === null && isset($references["reference[name_" . ($i+1) . "]"])) {
                                $name = $references["reference[name_" . ($i+1) . "]"] ?? '';
                                $address = $references["reference[address_" . ($i+1) . "]"] ?? '';
                                $contact = $references["reference[contact_" . ($i+1) . "]"] ?? '';
                            }

                            $NumRef = 56 + ($i + 1);
                            $sheetC4->setCellValue('A' . $NumRef, $name ?? '')
                                ->setCellValue('F' . $NumRef, $address ?? '')
                                ->setCellValue('G' . $NumRef, $contact ?? '');
                        }
                    }

                    // Government Issued ID (XI)
                    $declaration = $pdsData['pds-declaration'] ?? [];
                    $govId = $declaration['declaration[gov_id]'] ?? ($declaration['declaration[id]'] ?? ($declaration['gov_id'] ?? ($declaration['id'] ?? '')));
                    $govNo = $declaration['declaration[gov_id_no]'] ?? ($declaration['declaration[id_no]'] ?? ($declaration['declaration[idNo]'] ?? ($declaration['id_no'] ?? '')));
                    $issuance = $declaration['declaration[gov_id_issuance]'] ?? ($declaration['declaration[id_issue]'] ?? ($declaration['declaration[id_issue]'] ?? ($declaration['id_issue'] ?? '')));
                    $place = $declaration['declaration[gov_id_place]'] ?? ($declaration['declaration[id_place]'] ?? ($declaration['declaration[id_issue_place]'] ?? ($declaration['id_place'] ?? '')));
                    $date_accomplished = $declaration['declaration[date_accomplished]'] ?? ($declaration['date_accomplished'] ?? '');

                    $sheetC4->setCellValue('D66', $govId ?? '');
                    $sheetC4->setCellValue('D67', $govNo ?? '');
                    $sheetC4->setCellValue('D69', trim($issuance . (($issuance && $place) ? ' / ' : '') . $place));

                    $sheetC4->setCellValue('F69', $date_accomplished ? date('d/m/Y', strtotime($date_accomplished)) : '');
                    $sheetC3->setCellValue('I50', $date_accomplished ? date('d/m/Y', strtotime($date_accomplished)) : '');
                    $sheetC2->setCellValue('J47', $date_accomplished ? date('d/m/Y', strtotime($date_accomplished)) : '');

                }
    }

    private function implodeSeries(array $bucket, string $prefix): string
    {
        $items = [];

        for ($i = 0; $i < 7; $i++) {
            $value = trim((string) ($bucket[$prefix . '[' . $i . ']'] ?? ''));
            if ($value !== '') {
                $items[] = $value;
            }
        }

        return implode("\n", $items);
    }

    private function parsePdsDateToTimestamp(?string $date): int
    {
        if ($date === null) {
            return 0;
        }

        $d = trim((string) $date);
        if ($d === '') {
            return 0;
        }

        if (strtoupper($d) === 'PRESENT') {
            return PHP_INT_MAX;
        }

        // Try to parse common date formats; fall back to 0 when unparseable
        $ts = strtotime($d);
        if ($ts === false) {
            // try converting slashes to dashes (e.g. 01/02/2023)
            $alt = str_replace('/', '-', $d);
            $ts = strtotime($alt);
        }

        return $ts === false ? 0 : (int) $ts;
    }

    private function markIfSelected(string $actual, string $expected): string
    {
        return $this->normalizeExportValue($actual) === $this->normalizeExportValue($expected) ? '✓' : '';
    }

    private function markIfContains(string $actual, string $needle): string
    {
        return str_contains($this->normalizeExportValue($actual), $this->normalizeExportValue($needle)) ? '✓' : '';
    }

    private function normalizeExportValue(string $value): string
    {
        $normalized = strtolower(trim($value));

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    private function putCell(Worksheet $sheet, string $cell, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        $sheet->setCellValue($cell, (string) $value);

        if ($value === '✓') {
            $styleArray = [
                'alignment' => [
                    'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                ],
                'font' => [
                    'name' => 'Arial',
                    'size' => 12,
                    'bold' => true,
                ],
            ];
            $sheet->getStyle($cell)->applyFromArray($styleArray);
        }
    }

    
    private function safeMerge(Worksheet $sheet, string $range): void
    {
        $existing = $sheet->getMergeCells();

        foreach ($existing as $merged) {
            if ($this->rangesIntersect($merged, $range)) {
                $sheet->unmergeCells($merged);
            }
        }

        $sheet->mergeCells($range);
    }

    private function rangesIntersect(string $a, string $b): bool
    {
        [$aStart, $aEnd] = explode(':', $a) + [1 => $a];
        [$bStart, $bEnd] = explode(':', $b) + [1 => $b];

        [$aCol1, $aRow1] = $this->splitCell($aStart);
        [$aCol2, $aRow2] = $this->splitCell($aEnd);
        [$bCol1, $bRow1] = $this->splitCell($bStart);
        [$bCol2, $bRow2] = $this->splitCell($bEnd);

        $hIntersect = !($aCol2 < $bCol1 || $bCol2 < $aCol1);
        $vIntersect = !($aRow2 < $bRow1 || $bRow2 < $aRow1);

        return $hIntersect && $vIntersect;
    }

    private function splitCell(string $cell): array
    {
        $col = preg_replace('/[0-9]/', '', $cell);
        $row = preg_replace('/[^0-9]/', '', $cell);

        return [Coordinate::columnIndexFromString($col), (int) $row];
    }

    
    private function safeMergePreserve(Worksheet $sheet, string $range): void
    {
        [$start, $end] = explode(':', $range) + [1 => $range];
        [$startCol, $startRow] = $this->splitCell($start);
        [$endCol, $endRow] = $this->splitCell($end);

        $preserve = '';
        for ($r = $startRow; $r <= $endRow && $preserve === ''; $r++) {
            for ($c = $startCol; $c <= $endCol; $c++) {
                $cell = Coordinate::stringFromColumnIndex($c) . $r;
                $val = trim((string) $sheet->getCell($cell)->getValue());
                if ($val !== '') {
                    $preserve = $val;
                    break 2;
                }
            }
        }

        $this->safeMerge($sheet, $range);

        // Set preserved value into top-left cell of merged range and center it
        $topLeft = $start;
        if ($preserve !== '') {
            $sheet->setCellValue($topLeft, $preserve);
            $sheet->getStyle($topLeft)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($topLeft)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
            $sheet->getStyle($topLeft)->getAlignment()->setWrapText(true);
        }
    }

    /**
     * Apply password protection to every sheet in the workbook.
     * Password format: FIRSTNAME + first letter of LASTNAME (uppercase).
     */
    private function protectAllSheets(Spreadsheet $spreadsheet, User $user): void
    {
        $first = $user->first_name ?? ($user->firstname ?? '');
        $last  = $user->last_name ?? ($user->lastname ?? '');
        $password = strtoupper($first . substr((string) $last, 0, 1));

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            // Lock every cell in the used range
            $highestRow = $sheet->getHighestRow();
            $highestCol = $sheet->getHighestColumn();
            $range = 'A1:' . $highestCol . $highestRow;
            $sheet->getStyle($range)->getProtection()->setLocked(
                \PhpOffice\PhpSpreadsheet\Style\Protection::PROTECTION_PROTECTED
            );

            // Enable sheet protection with password
            $protection = $sheet->getProtection();
            $protection->setSheet(true);
            $protection->setPassword($password);
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
            $protection->setSelectLockedCells(false);
            $protection->setSelectUnlockedCells(false);
        }
    }

    private function removeInvalidDefinedNames(Spreadsheet $spreadsheet): void
    {
        $definedNames = $spreadsheet->getDefinedNames();

        foreach ($definedNames as $definedName) {
            $worksheet = $definedName->getWorksheet();
            $scope = $worksheet instanceof Worksheet ? $worksheet : null;
            $spreadsheet->removeDefinedName($definedName->getName(), $scope);
        }
    }

    /**
     * Remove any sheets from the workbook that are not in the allowed list.
     *
     * @param  string[]  $allowedNames
     */
    private function removeExtraSheets(Spreadsheet $spreadsheet, array $allowedNames): void
    {
        $allowed = array_map('strtolower', $allowedNames);

        for ($i = $spreadsheet->getSheetCount() - 1; $i >= 0; $i--) {
            $name = strtolower($spreadsheet->getSheet($i)->getTitle());
            if (!in_array($name, $allowed, true)) {
                $spreadsheet->removeSheetByIndex($i);
            }
        }
    }
}
