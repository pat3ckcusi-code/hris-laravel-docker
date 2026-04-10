<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Seed the application's departments (parent and child offices).
     */
    public function run(): void
    {
        $parentDepartmentNames = [
            "City Mayor's Office",
            "City Vice-Mayor's Office",
            "City Administrator' s Office",
            'City Human Resource Management Department',
            'City Planning and Development Department',
            'City Civil Registry Department',
            'City General Services Department',
            'City Budget Department',
            'City Accounting and Internal Audit Department',
            'City Treasury Department',
            "City Assessor' s Department",
            'City Legal Department',
            'City Health and Sanitation Department',
            'City Social Welfare and Development Department',
            'City Agricultural Services Department',
            'City Veterinary Services Department',
            'City Engineering and Public Works Department',
            'City Architectural Planning and Design Department',
            'City Education Department',
            'City Trade and Industry Department',
            'City Economic and Enterprise Department',
            'City Public Safety Department',
            'City Environment and Natural Resources Department',
            'City Youth and Sports Development Department',
            'City College of Calapan',
            'City Housing and Urban Settlement Department',
            'City Disaster Risk Reduction and ManagementDepartment',
            'City Public Employment Services Office',
        ];

        $mayorChildOfficeNames = [
            'City Tourism, Culture and Arts Department',
            'City Fisheries and Aquatic Resources Department',
            'Bids and Award Committee Office',
            'Management Information Office',
            'City Health Care Card Office',
            'City Nutrition Office',
            'Community Affairs Office',
            'Business Permit and Licensing Office',
            'City Cooperative and Development Office',
            'Office of the Senior Citizen',
            'Calapan City Public Library',
            'Office of the Persons With Disability',
            'City Information Office',
            'City Population Office',
            'Barangay Development Affairs Office',
            'Gender and Development Office',
        ];

        $departments = [];
        foreach ($parentDepartmentNames as $index => $name) {
            $children = [];
            if ($name === "City Mayor's Office") {
                foreach ($mayorChildOfficeNames as $childIndex => $childName) {
                    $children[] = [
                        'DeptCode' => 'DEPT-001-C' . str_pad((string) ($childIndex + 1), 2, '0', STR_PAD_LEFT),
                        'Dept_name' => $childName,
                        'EmpNo' => 'UNASSIGNED',
                        'Designation' => 'Office Head',
                    ];
                }
            }

            $departments[] = [
                'DeptCode' => 'DEPT-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                'Dept_name' => $name,
                'EmpNo' => 'UNASSIGNED',
                'Designation' => 'Department Head',
                'children' => $children,
            ];
        }

        foreach ($departments as $parentData) {
            $children = $parentData['children'] ?? [];
            unset($parentData['children']);

            $parent = Department::updateOrCreate(
                ['DeptCode' => $parentData['DeptCode']],
                $parentData + ['parent_dept_id' => null]
            );

            foreach ($children as $childData) {
                Department::updateOrCreate(
                    ['DeptCode' => $childData['DeptCode']],
                    $childData + ['parent_dept_id' => $parent->Dept_id]
                );
            }
        }
    }
}
