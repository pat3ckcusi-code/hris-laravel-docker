<?php

namespace Tests\Feature\ISO25010;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ISO/IEC 25010 - 8. Portability
 *
 * Tests: Adaptability, Installability, Replaceability
 */
class PortabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Database\Eloquent\Model::unguard();
    }

    protected function tearDown(): void
    {
        \Illuminate\Database\Eloquent\Model::reguard();
        parent::tearDown();
    }

    private function createDepartment(): Department
    {
        return Department::create([
            'DeptCode' => 'TST',
            'Dept_name' => 'Test Department',
            'EmpNo' => 'DH' . uniqid(),
            'Designation' => 'Department Head',
        ]);
    }

    private function createUser(string $role = 'employee'): User
    {
        $dept = $this->createDepartment();
        return User::create([
            'name' => 'Port User',
            'first_name' => 'Port',
            'last_name' => 'User',
            'email' => 'port' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'access_level' => $role,
            'employee_type' => 'permanent',
            'Dept_id' => $dept->Dept_id,
            'EmpNo' => 'P' . uniqid(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 8.1 INSTALLABILITY - migrations run cleanly on fresh database
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function all_migrations_run_successfully(): void
    {
        // RefreshDatabase already ran all migrations on SQLite in-memory DB.
        // Verify core tables exist.
        $requiredTables = [
            'users',
            'departments',
            'leave_requests',
            'leave_balances',
            'leave_dates',
            'eta',
            'locators',
            'travel_orders',
            'document_requests',
            'hr_audit_trails',
            'payroll_runs',
            'payroll_details',
            'plantillas',
            'employee_assignments',
            'salary_matrices',
            'earnings',
            'employee_earnings',
            'deductions',
            'employee_deductions',
            'loans',
            'dtrs',
            'leave_records',
            'payroll_exceptions',
            'approval_logs',
            'payslips',
            'payroll_audit_logs',
            'payroll_settings',
            'settings',
        ];

        foreach ($requiredTables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Required table '{$table}' does not exist after migrations"
            );
        }
    }

    /** @test */
    public function users_table_has_all_required_columns(): void
    {
        $requiredColumns = [
            'id', 'name', 'email', 'password',
            'EmpNo', 'access_level', 'employee_type',
            'first_name', 'last_name', 'middle_name',
            'Dept_id', 'date_hired', 'force_password_change',
        ];

        foreach ($requiredColumns as $column) {
            $this->assertTrue(
                Schema::hasColumn('users', $column),
                "Users table missing column: {$column}"
            );
        }
    }

    /** @test */
    public function payroll_tables_have_required_columns(): void
    {
        // payroll_runs
        $this->assertTrue(Schema::hasColumn('payroll_runs', 'period'));
        $this->assertTrue(Schema::hasColumn('payroll_runs', 'period_start'));
        $this->assertTrue(Schema::hasColumn('payroll_runs', 'period_end'));
        $this->assertTrue(Schema::hasColumn('payroll_runs', 'status'));
        $this->assertTrue(Schema::hasColumn('payroll_runs', 'locked_at'));
        $this->assertTrue(Schema::hasColumn('payroll_runs', 'created_by'));
        $this->assertTrue(Schema::hasColumn('payroll_runs', 'approved_by'));

        // payroll_details
        $this->assertTrue(Schema::hasColumn('payroll_details', 'payroll_run_id'));
        $this->assertTrue(Schema::hasColumn('payroll_details', 'employee_id'));
        $this->assertTrue(Schema::hasColumn('payroll_details', 'basic_salary'));
        $this->assertTrue(Schema::hasColumn('payroll_details', 'earnings'));
        $this->assertTrue(Schema::hasColumn('payroll_details', 'deductions'));
        $this->assertTrue(Schema::hasColumn('payroll_details', 'net_pay'));
        $this->assertTrue(Schema::hasColumn('payroll_details', 'lwop_deduction'));
        $this->assertTrue(Schema::hasColumn('payroll_details', 'loan_deduction'));
        $this->assertTrue(Schema::hasColumn('payroll_details', 'days_worked'));
        $this->assertTrue(Schema::hasColumn('payroll_details', 'absent_days'));
    }

    /** @test */
    public function dtr_table_has_am_pm_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('dtrs', 'time_in_am'));
        $this->assertTrue(Schema::hasColumn('dtrs', 'time_out_am'));
        $this->assertTrue(Schema::hasColumn('dtrs', 'time_in_pm'));
        $this->assertTrue(Schema::hasColumn('dtrs', 'time_out_pm'));
        $this->assertTrue(Schema::hasColumn('dtrs', 'late_minutes'));
        $this->assertTrue(Schema::hasColumn('dtrs', 'undertime_minutes'));
        $this->assertTrue(Schema::hasColumn('dtrs', 'is_absent'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 8.2 ADAPTABILITY - no environment-specific hard-coding
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function app_key_is_configurable_via_env(): void
    {
        $this->assertNotEmpty(config('app.key'), 'APP_KEY must be set');
    }

    /** @test */
    public function database_connection_is_configurable(): void
    {
        // Verify the database connection is controlled by environment config
        $driver = config('database.default');
        $this->assertContains($driver, ['mysql', 'sqlite', 'pgsql', 'sqlsrv'],
            'Database driver should be one of the supported drivers');
    }

    /** @test */
    public function mail_driver_is_configurable(): void
    {
        // Test environment uses array mailer
        $this->assertEquals('array', config('mail.default'));
    }

    /** @test */
    public function cache_driver_is_configurable(): void
    {
        $this->assertEquals('array', config('cache.default'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 8.3 REPLACEABILITY - core data operations work on any supported DB
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function crud_operations_work_on_test_database(): void
    {
        // Create
        $user = $this->createUser('employee');
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        // Read
        $found = User::find($user->id);
        $this->assertNotNull($found);

        // Update
        $user->update(['first_name' => 'Updated']);
        $this->assertEquals('Updated', $user->fresh()->first_name);

        // Delete
        $user->delete();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /** @test */
    public function department_hierarchy_works_on_test_database(): void
    {
        $parent = Department::create([
            'DeptCode' => 'EXEC',
            'Dept_name' => 'Executive',
            'EmpNo' => 'EXEC001',
            'Designation' => 'Executive Director',
        ]);

        $child = Department::create([
            'DeptCode' => 'HR',
            'Dept_name' => 'Human Resources',
            'parent_dept_id' => $parent->Dept_id,
            'EmpNo' => 'HR001',
            'Designation' => 'HR Director',
        ]);

        $this->assertNotNull($child->parent);
        $this->assertEquals('Executive', $child->parent->Dept_name);
    }

    /** @test */
    public function setting_model_works_for_system_configuration(): void
    {
        // The migration seeds a default settings row. Update it instead of creating a new one.
        $settings = \App\Models\Setting::first();
        $this->assertNotNull($settings, 'Settings row should be seeded by migration');

        $settings->update([
            'records_enabled' => true,
            'leave_enabled' => true,
            'frontdesk_enabled' => false,
        ]);

        $settings->refresh();
        $this->assertTrue($settings->records_enabled);
        $this->assertTrue($settings->leave_enabled);
        $this->assertFalse($settings->frontdesk_enabled);
    }

    /** @test */
    public function payroll_run_can_be_created_with_all_fields(): void
    {
        $admin = $this->createUser('payroll-manager');

        $run = \App\Models\PayrollRun::create([
            'period' => '2026-04 2nd',
            'period_start' => '2026-04-16',
            'period_end' => '2026-04-30',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $this->assertNotNull($run->id);
        $this->assertEquals('draft', $run->status);
        $this->assertEquals('2026-04-16', $run->period_start->format('Y-m-d'));
        $this->assertEquals('2026-04-30', $run->period_end->format('Y-m-d'));
    }
}
