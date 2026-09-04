<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\DtrExcuse;
use App\Models\OicAssignment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * DTR Excuse abuse detection: Time Keeper / HR Manager company-wide section
 * flagging employees whose excuse-filing frequency crosses the configurable
 * monthly threshold in a CSC MC No. 04, s. 1991 "habitual" pattern (2
 * consecutive months, or 2 months within the same semester).
 */
class DtrExcuseControllerTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeDepartment(string $name): Department
    {
        return Department::create([
            'DeptCode' => strtoupper(str_replace(' ', '_', $name)),
            'Dept_name' => $name,
            'Designation' => $name,
        ]);
    }

    /**
     * The employee's own last name also legitimately appears in the plain
     * excuse-instance list above the abuse section (since the seeded
     * DtrExcuse rows render there too), so a plain assertSee/assertDontSee
     * against the whole page can't distinguish "flagged" from "merely has
     * excuses on file." Slice out just the abuse section's own markup.
     */
    private function abuseSectionHtml(string $content): string
    {
        $start = strpos($content, 'Possible DTR Excuse Abuse');
        if ($start === false) {
            return '';
        }

        $end = strpos($content, 'id="confirm-overlay"', $start);

        return substr($content, $start, $end !== false ? $end - $start : null);
    }

    private function seedExcuses(User $employee, string $yearMonth, int $count, int $startDay = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            DtrExcuse::create([
                'user_id' => $employee->id,
                'date' => sprintf('%s-%02d', $yearMonth, $startDay + $i),
                'excuse_type' => 'other',
                'is_full_day' => true,
                'excuse_am_in' => true,
                'excuse_am_out' => true,
                'excuse_pm_in' => true,
                'excuse_pm_out' => true,
            ]);
        }
    }

    public function test_time_keeper_and_hr_manager_can_see_abuse_section(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'Habitual']);

        $this->seedExcuses($employee, '2026-03', 3);
        $this->seedExcuses($employee, '2026-04', 3);

        $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026]))
            ->assertStatus(200)
            ->assertSee('Possible DTR Excuse Abuse')
            ->assertSee('Habitual');

        $this->actingAs($this->createHRManager())
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026]))
            ->assertStatus(200)
            ->assertSee('Possible DTR Excuse Abuse')
            ->assertSee('Habitual');
    }

    public function test_department_head_and_administrative_officer_cannot_see_abuse_section(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'HabitualHidden']);

        $this->seedExcuses($employee, '2026-03', 3);
        $this->seedExcuses($employee, '2026-04', 3);

        // HabitualHidden's excuse rows legitimately appear in the plain
        // excuse-instance list above (DH/AO are scoped to their own
        // department, which includes this employee) - the real signal that
        // the controller withholds abuse data from them is the section
        // heading itself being absent, not the employee's name.
        $this->actingAs($this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]))
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026]))
            ->assertStatus(200)
            ->assertDontSee('Possible DTR Excuse Abuse');

        $this->actingAs($this->createAdminOfficer(['Dept_id' => $deptA->Dept_id]))
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026]))
            ->assertStatus(200)
            ->assertDontSee('Possible DTR Excuse Abuse');
    }

    public function test_threshold_is_read_from_settings(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'FourPerMonth']);

        Setting::first()->update(['dtr_excuse_abuse_monthly_threshold' => 5]);

        // 4 excuses/month in 2 consecutive months - below the overridden threshold of 5.
        $this->seedExcuses($employee, '2026-03', 4);
        $this->seedExcuses($employee, '2026-04', 4);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026]));
        $this->assertStringNotContainsString('FourPerMonth', $this->abuseSectionHtml($response->getContent()));

        // Raise to 5/month - now crosses the overridden threshold.
        $this->seedExcuses($employee, '2026-03', 1, 5);
        $this->seedExcuses($employee, '2026-04', 1, 5);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026]));
        $this->assertStringContainsString('FourPerMonth', $this->abuseSectionHtml($response->getContent()));
    }

    public function test_two_consecutive_violation_months_flags_employee(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'Consecutive']);

        $this->seedExcuses($employee, '2026-03', 3);
        $this->seedExcuses($employee, '2026-04', 3);

        $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026]))
            ->assertSee('Consecutive')
            ->assertSee('Possible Abuse');
    }

    public function test_two_violation_months_same_semester_flags_employee(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'SameSemester']);

        // January and April - same first semester, not consecutive.
        $this->seedExcuses($employee, '2026-01', 3);
        $this->seedExcuses($employee, '2026-04', 3);

        $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026]))
            ->assertSee('SameSemester');
    }

    public function test_non_consecutive_different_semester_months_not_flagged(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'NotHabitual']);

        // March (semester 1) and August (semester 2) - not consecutive, not same semester.
        $this->seedExcuses($employee, '2026-03', 3);
        $this->seedExcuses($employee, '2026-08', 3);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026]));
        $this->assertStringNotContainsString('NotHabitual', $this->abuseSectionHtml($response->getContent()));
    }

    public function test_only_one_violation_month_is_not_flagged(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'OnlyOnce']);

        $this->seedExcuses($employee, '2026-05', 3);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026]));
        $this->assertStringNotContainsString('OnlyOnce', $this->abuseSectionHtml($response->getContent()));
    }

    public function test_abuse_section_is_company_wide_not_department_scoped(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptOther = $this->makeDepartment('Other Dept');
        $employee = $this->createEmployee(['Dept_id' => $deptOther->Dept_id, 'last_name' => 'OutsideTimeKeeperDept']);

        $this->seedExcuses($employee, '2026-03', 3);
        $this->seedExcuses($employee, '2026-04', 3);

        // No department_id filter applied - flagged employee from a different
        // department than any acting user's own must still appear.
        $this->actingAs($this->createTimeKeeper(['Dept_id' => $deptA->Dept_id]))
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026]))
            ->assertSee('OutsideTimeKeeperDept');
    }

    public function test_abuse_year_and_main_filters_do_not_reset_each_other(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'first_name' => 'Foo']);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.dtr-excuse.index', ['search' => 'Foo', 'abuse_year' => 2025]));

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString('value="Foo"', $content);
        $this->assertMatchesRegularExpression('/<option value="2025"[^>]*selected/', $content);
    }

    public function test_abuse_flags_are_paginated(): void
    {
        $deptA = $this->makeDepartment('Dept A');

        for ($i = 1; $i <= 16; $i++) {
            $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => "Flagged{$i}"]);
            $this->seedExcuses($employee, '2026-03', 3);
            $this->seedExcuses($employee, '2026-04', 3);
        }

        $page1 = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026, 'abuse_page' => 1]));
        $page2 = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026, 'abuse_page' => 2]));

        $page1->assertStatus(200);
        $page2->assertStatus(200);
        $this->assertNotSame($page1->getContent(), $page2->getContent());
    }

    public function test_flag_badge_carries_violation_details_scoped_to_violation_months(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'DetailedFlag']);

        // A non-violation month (only 1 excuse) - must not leak into the
        // violation-details payload even though it's within the same year.
        DtrExcuse::create([
            'user_id' => $employee->id,
            'date' => '2026-02-01',
            'excuse_type' => 'weather_disturbance',
            'is_full_day' => true,
            'excuse_am_in' => true,
            'excuse_am_out' => true,
            'excuse_pm_in' => true,
            'excuse_pm_out' => true,
            'reason' => 'SHOULD NOT APPEAR',
        ]);

        // 2 consecutive violation months, one excuse with a distinctive reason.
        $this->seedExcuses($employee, '2026-03', 3);
        DtrExcuse::create([
            'user_id' => $employee->id,
            'date' => '2026-04-01',
            'excuse_type' => 'power_interruption',
            'is_full_day' => false,
            'excuse_am_in' => true,
            'excuse_am_out' => false,
            'excuse_pm_in' => false,
            'excuse_pm_out' => false,
            'reason' => 'Brownout in the area',
        ]);
        $this->seedExcuses($employee, '2026-04', 2, 2);

        $response = $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.dtr-excuse.index', ['abuse_year' => 2026]));

        $response->assertStatus(200);
        $section = $this->abuseSectionHtml($response->getContent());

        $this->assertStringContainsString('dex-violation-trigger', $section);
        $this->assertStringContainsString('Brownout in the area', $section);
        $this->assertStringContainsString('Power Interruption', $section);
        $this->assertStringContainsString('AM In', $section);
        $this->assertStringNotContainsString('SHOULD NOT APPEAR', $section);
    }

    public function test_plain_employee_without_oic_cannot_access_dtr_excuses(): void
    {
        $this->actingAs($this->createEmployee())
            ->get(route('attendance.dtr-excuse.index'))
            ->assertStatus(403);
    }

    public function test_oic_covering_employee_can_manage_excuses_for_covered_department_only(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');

        $coveringEmployee = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $inDeptA = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'InDeptA']);
        $inDeptB = $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'last_name' => 'InDeptB']);

        OicAssignment::create([
            'user_id' => $coveringEmployee->id,
            'dept_id' => $deptA->Dept_id,
            'role' => 'department head',
            'appointed_by' => $this->createHRManager()->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->actingAs($coveringEmployee)
            ->get(route('attendance.dtr-excuse.index'))
            ->assertStatus(200)
            ->assertSee('InDeptA')
            ->assertDontSee('InDeptB');

        $this->actingAs($coveringEmployee)
            ->post(route('attendance.dtr-excuse.store'), [
                'user_ids' => [$inDeptA->id],
                'date' => '2026-06-10',
                'excuse_type' => 'other',
                'is_full_day' => true,
                'reason' => 'System outage in Dept A office.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('dtr_excuses', [
            'user_id' => $inDeptA->id,
            'date' => '2026-06-10',
        ]);

        $this->actingAs($coveringEmployee)
            ->post(route('attendance.dtr-excuse.store'), [
                'user_ids' => [$inDeptB->id],
                'date' => '2026-06-10',
                'excuse_type' => 'other',
                'is_full_day' => true,
                'reason' => 'System outage in Dept B office.',
            ])
            ->assertStatus(403);
    }
}
