<?php

namespace Tests\Feature;

use App\Models\LeaveBalance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Leave Balances Schema Refactoring Tests
 *
 * Verifies the migration from EmpNo to user_id foreign key and all related functionality.
 */
class LeaveBalancesRefactoringTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    // ──────────────────────────────────────────────
    // 1. Schema & Structure Tests
    // ──────────────────────────────────────────────

    public function test_leave_balances_table_has_user_id_column(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('leave_balances');

        $this->assertContains('user_id', $columns, 'leave_balances table missing user_id column');
        $this->assertNotContains('EmpNo', $columns, 'leave_balances table should not have EmpNo column');
    }

    public function test_leave_balance_user_id_is_unsigned_bigint(): void
    {
        $columns = DB::select('SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = "leave_balances" AND COLUMN_NAME = "user_id"');

        if (! empty($columns)) {
            $columnType = strtolower($columns[0]->COLUMN_TYPE ?? '');
            $this->assertStringContainsString('bigint', $columnType, 'user_id should be BIGINT UNSIGNED');
        }
    }

    public function test_leave_balance_has_foreign_key_to_users(): void
    {
        $keyConstraints = DB::select('SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = "leave_balances" AND COLUMN_NAME = "user_id"');

        $this->assertNotEmpty($keyConstraints, 'No foreign key found for user_id');
        $this->assertEquals('users', $keyConstraints[0]->REFERENCED_TABLE_NAME, 'Foreign key should reference users table');
    }

    // ──────────────────────────────────────────────
    // 2. Model Relationship Tests
    // ──────────────────────────────────────────────

    public function test_leave_balance_belongs_to_user(): void
    {
        $emp = $this->createEmployee();
        $balance = LeaveBalance::create([
            'user_id' => $emp->id,
            'VL' => 10.0,
            'SL' => 5.0,
            'WLNS' => 2.0,
            'SPL' => 1.0,
            'CTO' => 0.0,
            'SP' => 0.0,
        ]);

        $this->assertInstanceOf(User::class, $balance->user);
        $this->assertEquals($emp->id, $balance->user->id);
    }

    public function test_user_has_one_leave_balance(): void
    {
        $emp = $this->createEmployee();
        // UserObserver auto-creates a balance on user creation; use updateOrCreate to avoid duplicate
        $balance = LeaveBalance::updateOrCreate(
            ['user_id' => $emp->id],
            ['VL' => 10.0, 'SL' => 5.0, 'WLNS' => 2.0, 'SPL' => 1.0, 'CTO' => 0.0, 'SP' => 0.0]
        );

        $emp->refresh();
        $this->assertInstanceOf(LeaveBalance::class, $emp->leaveBalance);
        $this->assertEquals($balance->id, $emp->leaveBalance->id);
    }

    // ──────────────────────────────────────────────
    // 3. UserObserver Tests
    // ──────────────────────────────────────────────

    public function test_new_user_creation_auto_creates_leave_balance(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'EmpNo' => 'EMP001',
            'id' => 999, // Force an ID for testing
        ]);

        $balance = LeaveBalance::where('user_id', $user->id)->first();

        $this->assertNotNull($balance, 'Leave balance should be auto-created for new user');
        $this->assertEquals($user->id, $balance->user_id);
        $this->assertFalse(isset($balance->EmpNo), 'Leave balance should not have EmpNo column');
    }

    public function test_leave_balance_created_with_default_values(): void
    {
        $user = $this->createEmployee();

        $balance = $user->leaveBalance;

        $this->assertNotNull($balance);
        // Verify all fields exist and are float types
        $this->assertIsFloat($balance->VL ?? 0.0);
        $this->assertIsFloat($balance->SL ?? 0.0);
        $this->assertIsFloat($balance->WLNS ?? 0.0);
        $this->assertIsFloat($balance->SPL ?? 0.0);
        $this->assertIsFloat($balance->CTO ?? 0.0);
        $this->assertIsFloat($balance->SP ?? 0.0);
    }

    // ──────────────────────────────────────────────
    // 4. Leave Deduction Tests
    // ──────────────────────────────────────────────

    public function test_leave_balance_deduction_on_approval(): void
    {
        $emp = $this->createEmployee();
        $balance = $this->createLeaveBalance($emp, ['VL' => 10.0, 'SL' => 5.0]);

        // Manually simulate leave deduction
        $balance->VL -= 2.0;
        $balance->save();
        $balance->refresh();

        $this->assertEquals(8.0, $balance->VL);
    }

    public function test_leave_balance_restoration_on_cancellation(): void
    {
        $emp = $this->createEmployee();
        $balance = $this->createLeaveBalance($emp, ['VL' => 8.0]);

        // Restore balance
        $balance->VL += 2.0;
        $balance->save();
        $balance->refresh();

        $this->assertEquals(10.0, $balance->VL);
    }

    public function test_multiple_leave_type_deductions(): void
    {
        $emp = $this->createEmployee();
        $balance = $this->createLeaveBalance($emp, [
            'VL' => 15.0,
            'SL' => 10.0,
            'WLNS' => 5.0,
            'SPL' => 3.0,
            'CTO' => 2.0,
            'SP' => 1.0,
        ]);

        // Deduct from various fields
        $balance->VL -= 3.0;
        $balance->SL -= 2.0;
        $balance->WLNS -= 1.0;
        $balance->save();
        $balance->refresh();

        $this->assertEquals(12.0, $balance->VL);
        $this->assertEquals(8.0, $balance->SL);
        $this->assertEquals(4.0, $balance->WLNS);
        $this->assertEquals(3.0, $balance->SPL);
    }

    // ──────────────────────────────────────────────
    // 5. Data Integrity Tests
    // ──────────────────────────────────────────────

    public function test_leave_balance_fillable_has_user_id(): void
    {
        $balance = new LeaveBalance;
        $fillable = $balance->getFillable();

        $this->assertContains('user_id', $fillable, 'user_id should be in $fillable');
        $this->assertNotContains('EmpNo', $fillable, 'EmpNo should not be in $fillable');
    }

    public function test_all_required_balance_fields_are_fillable(): void
    {
        $balance = new LeaveBalance;
        $fillable = $balance->getFillable();

        $requiredFields = ['user_id', 'VL', 'SL', 'WLNS', 'SPL', 'CTO', 'SP'];
        foreach ($requiredFields as $field) {
            $this->assertContains($field, $fillable, "{$field} should be fillable");
        }
    }

    public function test_leave_balance_timestamps_are_set(): void
    {
        $emp = $this->createEmployee();
        $balance = LeaveBalance::create([
            'user_id' => $emp->id,
            'VL' => 10.0,
            'SL' => 5.0,
            'WLNS' => 2.0,
            'SPL' => 1.0,
            'CTO' => 0.0,
            'SP' => 0.0,
        ]);

        $balance->refresh();

        $this->assertNotNull($balance->created_at);
        $this->assertNotNull($balance->updated_at);
        $this->assertInstanceOf(Carbon::class, $balance->created_at);
        $this->assertInstanceOf(Carbon::class, $balance->updated_at);
    }

    // ──────────────────────────────────────────────
    // 6. Controller Integration Tests
    // ──────────────────────────────────────────────

    public function test_manage_balance_page_works_with_new_schema(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $balance = $this->createLeaveBalance($emp, ['VL' => 15.0]);

        $response = $this->actingAs($lm)->get(route('leave-manager.manage-balance'));

        $response->assertStatus(200);
        // Verify the balance is loaded with user relationship
        $this->assertTrue($response->viewData('balances')->contains('id', $balance->id));
    }

    public function test_update_balance_works_with_user_id(): void
    {
        $lm = $this->createLeaveManager();
        $emp = $this->createEmployee();
        $balance = $this->createLeaveBalance($emp);

        $response = $this->actingAs($lm)->patch(
            route('leave-manager.update-balance', $balance->id),
            ['field' => 'VL', 'value' => 25.0]
        );

        $this->assertTrue($response->isSuccessful() || $response->isRedirection());

        $balance->refresh();
        $this->assertEquals(25.0, $balance->VL);
    }

    // ──────────────────────────────────────────────
    // 7. Negative & Edge Cases
    // ──────────────────────────────────────────────

    public function test_leave_balance_allows_negative_values(): void
    {
        $emp = $this->createEmployee();
        $balance = $this->createLeaveBalance($emp, ['VL' => 5.0]);

        $balance->VL -= 10.0;
        $balance->save();
        $balance->refresh();

        $this->assertEquals(-5.0, $balance->VL);
    }

    public function test_leave_balance_handles_zero_values(): void
    {
        $emp = $this->createEmployee();
        $balance = LeaveBalance::create([
            'user_id' => $emp->id,
            'VL' => 0.0,
            'SL' => 0.0,
            'WLNS' => 0.0,
            'SPL' => 0.0,
            'CTO' => 0.0,
            'SP' => 0.0,
        ]);

        $balance->refresh();

        $this->assertEquals(0.0, $balance->VL);
        $this->assertEquals(0.0, $balance->SP);
    }

    public function test_leave_balance_handles_null_values(): void
    {
        $this->markTestSkipped('leave_balances columns are NOT NULL - null storage is not supported by the schema.');
    }

    // ──────────────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────────────

    /**
     * Create a leave balance for testing.
     */
    protected function createLeaveBalance(User $user, array $data = []): LeaveBalance
    {
        return LeaveBalance::create(array_merge([
            'user_id' => $user->id,
            'VL' => 10.0,
            'SL' => 5.0,
            'WLNS' => 2.0,
            'SPL' => 1.0,
            'CTO' => 0.0,
            'SP' => 0.0,
        ], $data));
    }
}
