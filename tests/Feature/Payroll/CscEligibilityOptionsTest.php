<?php

namespace Tests\Feature\Payroll;

use App\Models\CscEligibilityOption;
use App\Models\Plantilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * CSC Eligibility categories (admin-manageable catalog)
 *
 * Covers: index listing + usage count, key derivation from label, duplicate
 * label/key rejection, label-only editing (key stays immutable), the in-use
 * delete guard, and role access.
 */
class CscEligibilityOptionsTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_index_lists_seeded_categories_with_usage_count(): void
    {
        $manager = $this->createPayrollManager();

        Plantilla::create([
            'title' => 'Engineer II',
            'item_number' => '901',
            'salary_grade' => 16,
            'step' => 1,
            'employment_type' => 'permanent',
            'csc_eligibility' => 'professional',
        ]);

        $response = $this->actingAs($manager)->get(route('payroll.csc-eligibility.index'));

        $response->assertStatus(200)
            ->assertSee('Professional')
            ->assertSee('Sub-Professional')
            ->assertSee('No Required CSC');
        $this->assertStringContainsString('1 position(s)', $response->getContent());
    }

    public function test_store_creates_category_and_derives_key_from_label(): void
    {
        $manager = $this->createPayrollManager();

        $this->actingAs($manager)->post(route('payroll.csc-eligibility.store'), [
            'label' => 'Career Service Sub-Professional',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('csc_eligibility_options', [
            'key' => 'career_service_sub_professional',
            'label' => 'Career Service Sub-Professional',
        ]);
    }

    public function test_store_rejects_label_that_produces_duplicate_key(): void
    {
        $manager = $this->createPayrollManager();

        // "Sub Professional" (space) slugs to the same key as the seeded
        // "Sub-Professional" (hyphen): sub_professional.
        $this->actingAs($manager)->post(route('payroll.csc-eligibility.store'), [
            'label' => 'Sub Professional',
        ])->assertSessionHasErrors('label');

        $this->assertSame(3, CscEligibilityOption::count());
    }

    public function test_store_rejects_exact_duplicate_label(): void
    {
        $manager = $this->createPayrollManager();

        $this->actingAs($manager)->post(route('payroll.csc-eligibility.store'), [
            'label' => 'Professional',
        ])->assertSessionHasErrors('label');

        $this->assertSame(3, CscEligibilityOption::count());
    }

    public function test_update_changes_label_only_and_key_stays_immutable(): void
    {
        $manager = $this->createPayrollManager();
        $option = CscEligibilityOption::where('key', 'sub_professional')->firstOrFail();

        $this->actingAs($manager)->put(route('payroll.csc-eligibility.update', $option->id), [
            'label' => 'Sub-Professional Eligibility',
            'key' => 'spoofed_key',
        ])->assertSessionHasNoErrors();

        $option->refresh();
        $this->assertSame('Sub-Professional Eligibility', $option->label);
        $this->assertSame('sub_professional', $option->key);
    }

    public function test_update_rejects_duplicate_label(): void
    {
        $manager = $this->createPayrollManager();
        $option = CscEligibilityOption::where('key', 'sub_professional')->firstOrFail();

        $this->actingAs($manager)->put(route('payroll.csc-eligibility.update', $option->id), [
            'label' => 'Professional',
        ])->assertSessionHasErrors('label');

        $this->assertSame('Sub-Professional', $option->fresh()->label);
    }

    public function test_destroy_blocked_when_category_in_use(): void
    {
        $manager = $this->createPayrollManager();
        $option = CscEligibilityOption::where('key', 'none')->firstOrFail();

        Plantilla::create([
            'title' => 'Elected Councilor',
            'item_number' => '902',
            'salary_grade' => 1,
            'step' => 1,
            'employment_type' => 'permanent',
            'csc_eligibility' => 'none',
        ]);

        $this->actingAs($manager)->delete(route('payroll.csc-eligibility.destroy', $option->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('csc_eligibility_options', ['id' => $option->id]);
    }

    public function test_destroy_allowed_when_category_unused(): void
    {
        $manager = $this->createPayrollManager();
        $option = CscEligibilityOption::create([
            'key' => 'unused_category',
            'label' => 'Unused Category',
        ]);

        $this->actingAs($manager)->delete(route('payroll.csc-eligibility.destroy', $option->id))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('csc_eligibility_options', ['id' => $option->id]);
    }

    public function test_create_and_edit_actions_redirect_to_index(): void
    {
        $manager = $this->createPayrollManager();
        $option = CscEligibilityOption::where('key', 'none')->firstOrFail();

        $this->actingAs($manager)->get(route('payroll.csc-eligibility.create'))
            ->assertRedirect(route('payroll.csc-eligibility.index'));

        $this->actingAs($manager)->get(route('payroll.csc-eligibility.edit', $option->id))
            ->assertRedirect(route('payroll.csc-eligibility.index'));
    }

    public function test_only_payroll_manager_can_access(): void
    {
        $employee = $this->createEmployee();

        $this->actingAs($employee)->get(route('payroll.csc-eligibility.index'))
            ->assertStatus(403);
    }
}
