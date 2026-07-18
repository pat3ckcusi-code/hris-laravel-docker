<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeeAssignment;
use App\Models\Plantilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Plantilla management UI
 *
 * Covers: index filters (search / department / vacancy), item_number +
 * department CRUD fields, and the safe assignment flow (one active
 * assignment per employee and per plantilla item, salary column sync).
 */
class PlantillaUiTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makePlantilla(array $overrides = []): Plantilla
    {
        return Plantilla::create(array_merge([
            'title' => 'Administrative Aide II',
            'item_number' => '901',
            'department' => 'TEST OFFICE',
            'salary_grade' => 2,
            'step' => 5,
            'employment_type' => 'permanent',
        ], $overrides));
    }

    public function test_index_loads_and_filters_by_search_and_vacancy(): void
    {
        $manager = $this->createPayrollManager();
        $filled = $this->makePlantilla();
        $vacant = $this->makePlantilla(['title' => 'Nurse I', 'item_number' => '902', 'salary_grade' => 15]);
        $employee = $this->createEmployee(['last_name' => 'Santos', 'first_name' => 'Ana']);
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $filled->id,
            'start_date' => '2026-01-01',
        ]);

        $this->actingAs($manager)->get(route('payroll.plantilla.index'))
            ->assertStatus(200)
            ->assertSee('901')
            ->assertSee('Santos, Ana');

        $this->actingAs($manager)->get(route('payroll.plantilla.index', ['status' => 'vacant']))
            ->assertStatus(200)
            ->assertSee('Nurse I')
            ->assertDontSee('Administrative Aide II');

        $this->actingAs($manager)->get(route('payroll.plantilla.index', ['search' => '902']))
            ->assertStatus(200)
            ->assertSee('Nurse I')
            ->assertDontSee('Administrative Aide II');

        // Search by incumbent name finds the filled item
        $this->actingAs($manager)->get(route('payroll.plantilla.index', ['search' => 'Santos']))
            ->assertStatus(200)
            ->assertSee('Administrative Aide II');
    }

    public function test_store_and_update_accept_item_number_and_department(): void
    {
        $manager = $this->createPayrollManager();

        $this->actingAs($manager)->post(route('payroll.plantilla.store'), [
            'title' => 'Engineer II',
            'item_number' => '345',
            'department' => 'City Engineering Department',
            'salary_grade' => 16,
            'step' => 2,
            'employment_type' => 'permanent',
        ])->assertRedirect(route('payroll.plantilla.index'));

        $plantilla = Plantilla::where('item_number', '345')->first();
        $this->assertNotNull($plantilla);
        $this->assertSame('City Engineering Department', $plantilla->department);

        // Duplicate item_number rejected
        $this->actingAs($manager)->post(route('payroll.plantilla.store'), [
            'title' => 'Engineer I',
            'item_number' => '345',
            'salary_grade' => 12,
            'step' => 1,
            'employment_type' => 'permanent',
        ])->assertSessionHasErrors('item_number');

        // Update keeps its own item_number without tripping the unique rule
        $this->actingAs($manager)->put(route('payroll.plantilla.update', $plantilla->id), [
            'title' => 'Engineer II',
            'item_number' => '345',
            'department' => 'CEPWD',
            'salary_grade' => 16,
            'step' => 3,
            'employment_type' => 'permanent',
        ])->assertSessionHasNoErrors();

        $this->assertSame('CEPWD', $plantilla->fresh()->department);
        $this->assertSame(3, $plantilla->fresh()->step);
    }

    public function test_store_and_update_accept_csc_eligibility_and_index_filters_by_it(): void
    {
        $manager = $this->createPayrollManager();

        $this->actingAs($manager)->post(route('payroll.plantilla.store'), [
            'title' => 'Administrative Assistant III',
            'item_number' => '777',
            'salary_grade' => 9,
            'step' => 1,
            'employment_type' => 'permanent',
            'csc_eligibility' => 'sub_professional',
        ])->assertSessionHasNoErrors();

        $plantilla = Plantilla::where('item_number', '777')->first();
        $this->assertSame('sub_professional', $plantilla->csc_eligibility);

        $this->actingAs($manager)->put(route('payroll.plantilla.update', $plantilla->id), [
            'title' => 'Administrative Assistant III',
            'item_number' => '777',
            'salary_grade' => 9,
            'step' => 1,
            'employment_type' => 'permanent',
            'csc_eligibility' => 'professional',
        ])->assertSessionHasNoErrors();

        $this->assertSame('professional', $plantilla->fresh()->csc_eligibility);

        $this->actingAs($manager)->post(route('payroll.plantilla.store'), [
            'title' => 'Bogus Position',
            'salary_grade' => 5,
            'step' => 1,
            'employment_type' => 'permanent',
            'csc_eligibility' => 'not-a-real-option',
        ])->assertSessionHasErrors('csc_eligibility');

        $noneRequired = $this->makePlantilla(['title' => 'Elected Councilor', 'item_number' => '778', 'csc_eligibility' => 'none']);

        // Both items are given incumbents so neither leaks into the Promote
        // modal's vacant-target dropdown, which always lists every vacant
        // item regardless of the index page's filters.
        EmployeeAssignment::create([
            'employee_id' => $this->createEmployee()->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => '2026-01-01',
        ]);
        EmployeeAssignment::create([
            'employee_id' => $this->createEmployee()->id,
            'plantilla_id' => $noneRequired->id,
            'start_date' => '2026-01-01',
        ]);

        $this->actingAs($manager)->get(route('payroll.plantilla.index', ['eligibility' => 'professional']))
            ->assertStatus(200)
            ->assertSee('Administrative Assistant III')
            ->assertDontSee('Elected Councilor');

        $this->actingAs($manager)->get(route('payroll.plantilla.index', ['eligibility' => 'none']))
            ->assertStatus(200)
            ->assertSee('Elected Councilor')
            ->assertDontSee('Administrative Assistant III');
    }

    public function test_store_and_update_accept_qualification_standards_and_show_page_displays_them(): void
    {
        $manager = $this->createPayrollManager();

        $this->actingAs($manager)->post(route('payroll.plantilla.store'), [
            'title' => 'Administrative Assistant I (Bookbinder III)',
            'item_number' => '140',
            'salary_grade' => 7,
            'step' => 1,
            'employment_type' => 'permanent',
            'csc_eligibility' => 'none',
            'education' => 'Elementary School Graduate',
            'training' => 'None required',
            'experience' => 'None required',
            'competency' => 'Integrity/Honesty, Attention To Detail, Communication',
        ])->assertSessionHasNoErrors();

        $plantilla = Plantilla::where('item_number', '140')->first();
        $this->assertSame('Elementary School Graduate', $plantilla->education);
        $this->assertSame('Integrity/Honesty, Attention To Detail, Communication', $plantilla->competency);

        $this->actingAs($manager)->get(route('payroll.plantilla.show', $plantilla->id))
            ->assertStatus(200)
            ->assertSee('Elementary School Graduate')
            ->assertSee('Integrity/Honesty, Attention To Detail, Communication');

        $this->actingAs($manager)->put(route('payroll.plantilla.update', $plantilla->id), [
            'title' => $plantilla->title,
            'item_number' => '140',
            'salary_grade' => 7,
            'step' => 1,
            'employment_type' => 'permanent',
            'education' => 'High School Graduate',
        ])->assertSessionHasNoErrors();

        $this->assertSame('High School Graduate', $plantilla->fresh()->education);
        // $request->only() drops keys absent from the request entirely, so
        // omitting a field from the update leaves its stored value untouched
        // rather than clearing it (same as the other optional CRUD fields).
        $this->assertSame('Integrity/Honesty, Attention To Detail, Communication', $plantilla->fresh()->competency);
    }

    public function test_assigning_employee_ends_their_previous_active_assignment(): void
    {
        $manager = $this->createPayrollManager();
        $employee = $this->createEmployee();
        $old = $this->makePlantilla();
        $new = $this->makePlantilla(['title' => 'Administrative Aide III', 'item_number' => '902', 'salary_grade' => 3, 'step' => 1]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $old->id,
            'start_date' => '2026-01-01',
        ]);

        $this->actingAs($manager)->post(route('payroll.plantilla.assignments.store', $new->id), [
            'employee_id' => $employee->id,
            'start_date' => '2026-07-01',
        ])->assertSessionHas('status');

        $active = EmployeeAssignment::where('employee_id', $employee->id)->whereNull('end_date')->get();
        $this->assertCount(1, $active);
        $this->assertSame($new->id, $active->first()->plantilla_id);
        $this->assertSame(
            '2026-06-30',
            EmployeeAssignment::where('plantilla_id', $old->id)->first()->end_date->toDateString()
        );

        // Denormalized user columns follow the new position
        $this->assertSame(3, $employee->fresh()->salary_grade);
        $this->assertSame(1, $employee->fresh()->salary_step);
    }

    public function test_assigning_to_an_item_with_active_incumbent_is_rejected(): void
    {
        $manager = $this->createPayrollManager();
        $plantilla = $this->makePlantilla();
        $holder = $this->createEmployee();
        $other = $this->createEmployee();

        EmployeeAssignment::create([
            'employee_id' => $holder->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => '2026-01-01',
        ]);

        $this->actingAs($manager)->post(route('payroll.plantilla.assignments.store', $plantilla->id), [
            'employee_id' => $other->id,
            'start_date' => '2026-07-01',
        ])->assertSessionHas('error');

        $this->assertSame(1, EmployeeAssignment::where('plantilla_id', $plantilla->id)->count());
    }

    public function test_promote_moves_employee_to_vacant_item_and_updates_designation(): void
    {
        $manager = $this->createPayrollManager();
        $employee = $this->createEmployee(['designation' => 'Administrative Aide II']);
        $current = $this->makePlantilla();
        $higher = $this->makePlantilla([
            'title' => 'Administrative Assistant I',
            'item_number' => '910',
            'salary_grade' => 7,
            'step' => 1,
        ]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $current->id,
            'start_date' => '2026-01-01',
        ]);

        $this->actingAs($manager)->post(route('payroll.plantilla.promote'), [
            'employee_id' => $employee->id,
            'plantilla_id' => $higher->id,
            'effective_date' => '2026-08-01',
        ])->assertRedirect(route('payroll.plantilla.show', $higher->id))
            ->assertSessionHas('status');

        $active = EmployeeAssignment::where('employee_id', $employee->id)->whereNull('end_date')->get();
        $this->assertCount(1, $active);
        $this->assertSame($higher->id, $active->first()->plantilla_id);
        $this->assertSame('2026-08-01', $active->first()->start_date->toDateString());
        $this->assertSame(
            '2026-07-31',
            EmployeeAssignment::where('plantilla_id', $current->id)->first()->end_date->toDateString()
        );

        $employee->refresh();
        $this->assertSame(7, $employee->salary_grade);
        $this->assertSame(1, $employee->salary_step);
        $this->assertSame('Administrative Assistant I', $employee->designation);
        $this->assertSame('2026-08-01', $employee->date_of_last_promotion?->toDateString());

        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $manager->id,
            'module' => 'payroll',
            'action' => 'promotion',
            'target_id' => $employee->id,
        ]);
    }

    public function test_promote_rejects_an_occupied_target_position(): void
    {
        $manager = $this->createPayrollManager();
        $employee = $this->createEmployee();
        $holder = $this->createEmployee();
        $current = $this->makePlantilla();
        $target = $this->makePlantilla(['title' => 'Nurse I', 'item_number' => '911', 'salary_grade' => 15]);

        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $current->id, 'start_date' => '2026-01-01']);
        EmployeeAssignment::create(['employee_id' => $holder->id, 'plantilla_id' => $target->id, 'start_date' => '2026-01-01']);

        $this->actingAs($manager)->post(route('payroll.plantilla.promote'), [
            'employee_id' => $employee->id,
            'plantilla_id' => $target->id,
            'effective_date' => '2026-08-01',
        ])->assertSessionHas('error');

        // Nothing moved
        $this->assertSame(
            $current->id,
            EmployeeAssignment::where('employee_id', $employee->id)->whereNull('end_date')->first()->plantilla_id
        );
    }

    public function test_reports_page_shows_vacancies_promotions_and_activity(): void
    {
        $manager = $this->createPayrollManager();
        $employee = $this->createEmployee(['last_name' => 'Reyes', 'first_name' => 'Maria']);
        $current = $this->makePlantilla();
        $higher = $this->makePlantilla(['title' => 'Administrative Officer I', 'item_number' => '920', 'salary_grade' => 10]);
        $vacant = $this->makePlantilla(['title' => 'Nurse III', 'item_number' => '921', 'salary_grade' => 17]);

        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $current->id, 'start_date' => '2026-01-01']);

        $this->actingAs($manager)->post(route('payroll.plantilla.promote'), [
            'employee_id' => $employee->id,
            'plantilla_id' => $higher->id,
            'effective_date' => '2026-08-01',
        ]);

        $this->actingAs($manager)->get(route('payroll.plantilla.reports'))
            ->assertStatus(200)
            ->assertSee('Nurse III')                    // vacant list
            ->assertSee('Reyes, Maria')                 // promotion history
            ->assertSee('Administrative Officer I')     // promoted-to position
            ->assertSee($manager->name);                // processed by
    }

    public function test_service_trail_shows_position_history_and_change_log(): void
    {
        $manager = $this->createPayrollManager();
        $employee = $this->createEmployee(['last_name' => 'Bautista', 'first_name' => 'Liza']);
        $employee->forceFill([
            'date_of_original_appointment' => '2010-03-15',
            'date_of_last_promotion' => '2020-06-01',
        ])->save();

        $old = $this->makePlantilla();
        $new = $this->makePlantilla(['title' => 'Administrative Officer II', 'item_number' => '930', 'salary_grade' => 11]);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $old->id, 'start_date' => '2026-01-01']);

        $this->actingAs($manager)->post(route('payroll.plantilla.promote'), [
            'employee_id' => $employee->id,
            'plantilla_id' => $new->id,
            'effective_date' => '2026-08-01',
        ]);

        // Empty state without a selection
        $this->actingAs($manager)->get(route('payroll.plantilla.service-trail'))
            ->assertStatus(200)
            ->assertSee('Select an employee');

        $response = $this->actingAs($manager)
            ->get(route('payroll.plantilla.service-trail', ['employee_id' => $employee->id]));

        $response->assertStatus(200)
            ->assertSee('Bautista, Liza')
            ->assertSee('Mar 15, 2010')                      // original appointment
            ->assertSee('Administrative Aide II')            // ended position in history
            ->assertSee('Administrative Officer II')         // active position
            ->assertSee('Jul 31, 2026')                      // end date of old assignment
            ->assertSee('Promotion')                         // change log entry
            ->assertSee($manager->name);                     // actor
    }

    public function test_add_past_position_creates_ended_assignment_without_disturbing_current_one(): void
    {
        $manager = $this->createPayrollManager();
        $employee = $this->createEmployee(['last_name' => 'Reyes', 'first_name' => 'Carlo']);
        $current = $this->makePlantilla(['title' => 'Administrative Officer II', 'item_number' => '940', 'salary_grade' => 11, 'step' => 3]);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $current->id, 'start_date' => '2026-01-01']);
        $employee->forceFill(['salary_grade' => 11, 'salary_step' => 3])->save();

        $response = $this->actingAs($manager)->post(route('payroll.plantilla.history.store'), [
            'employee_id' => $employee->id,
            'title' => 'Administrative Aide IV',
            'department' => 'Old Records Section',
            'salary_grade' => 4,
            'step' => 2,
            'employment_type' => 'permanent',
            'start_date' => '2015-06-01',
            'end_date' => '2019-12-31',
        ]);

        $response->assertRedirect(route('payroll.plantilla.service-trail', ['employee_id' => $employee->id]));
        $response->assertSessionHas('status');

        $historical = EmployeeAssignment::whereHas('plantilla', fn ($q) => $q->where('title', 'Administrative Aide IV'))->first();
        $this->assertNotNull($historical);
        $this->assertSame('2015-06-01', $historical->start_date->toDateString());
        $this->assertSame('2019-12-31', $historical->end_date->toDateString());
        $this->assertNull($historical->plantilla->item_number);

        // Current active assignment and synced salary are untouched
        $this->assertSame(
            $current->id,
            EmployeeAssignment::where('employee_id', $employee->id)->whereNull('end_date')->first()->plantilla_id
        );
        $this->assertSame(11, $employee->fresh()->salary_grade);
        $this->assertSame(3, $employee->fresh()->salary_step);

        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $manager->id,
            'module' => 'payroll',
            'action' => 'historical_assignment_added',
            'target_id' => $employee->id,
        ]);

        // Shows up in the trail view
        $this->actingAs($manager)->get(route('payroll.plantilla.service-trail', ['employee_id' => $employee->id]))
            ->assertStatus(200)
            ->assertSee('Administrative Aide IV')
            ->assertSee('Old Records Section');
    }

    public function test_add_past_position_does_not_inflate_total_or_vacant_stats(): void
    {
        $manager = $this->createPayrollManager();
        $employee = $this->createEmployee();
        $filled = $this->makePlantilla();
        $vacant = $this->makePlantilla(['title' => 'Nurse I', 'item_number' => '902', 'salary_grade' => 15]);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $filled->id, 'start_date' => '2026-01-01']);

        $before = $this->actingAs($manager)->get(route('payroll.plantilla.index'));
        $before->assertSee('Total Positions');

        $this->actingAs($manager)->post(route('payroll.plantilla.history.store'), [
            'employee_id' => $employee->id,
            'title' => 'Administrative Aide IV',
            'salary_grade' => 4,
            'step' => 2,
            'employment_type' => 'permanent',
            'start_date' => '2015-06-01',
            'end_date' => '2019-12-31',
        ])->assertSessionHas('status');

        // The historical entry is a real plantillas row, but must not be
        // counted as an additional live position or an assignable vacancy.
        $this->assertSame(2, Plantilla::where('is_historical', false)->count());
        $this->assertSame(1, Plantilla::where('is_historical', true)->count());

        $this->actingAs($manager)->get(route('payroll.plantilla.index'))
            ->assertViewHas('stats', function ($stats) {
                return $stats['total'] === 2 && $stats['vacant'] === 1 && $stats['filled'] === 1;
            });

        $this->actingAs($manager)->get(route('payroll.plantilla.reports'))
            ->assertViewHas('stats', function ($stats) {
                return $stats['total'] === 2 && $stats['vacant'] === 1 && $stats['filled'] === 1;
            })
            ->assertDontSee('Administrative Aide IV');
    }

    public function test_add_past_position_requires_an_end_date(): void
    {
        $manager = $this->createPayrollManager();
        $employee = $this->createEmployee();

        $this->actingAs($manager)->post(route('payroll.plantilla.history.store'), [
            'employee_id' => $employee->id,
            'title' => 'Administrative Aide I',
            'salary_grade' => 1,
            'step' => 1,
            'employment_type' => 'permanent',
            'start_date' => '2015-01-01',
        ])->assertSessionHasErrors('end_date');

        $this->assertSame(0, EmployeeAssignment::where('employee_id', $employee->id)->count());
    }

    public function test_removing_the_only_assignment_clears_user_salary_columns(): void
    {
        $manager = $this->createPayrollManager();
        $employee = $this->createEmployee();
        $plantilla = $this->makePlantilla();

        $assignment = EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => '2026-01-01',
        ]);
        $employee->update(['salary_grade' => 2, 'salary_step' => 5]);

        $this->actingAs($manager)->delete(
            route('payroll.plantilla.assignments.destroy', [$plantilla->id, $assignment->id])
        )->assertSessionHas('status');

        $this->assertNull($employee->fresh()->salary_grade);
    }
}
