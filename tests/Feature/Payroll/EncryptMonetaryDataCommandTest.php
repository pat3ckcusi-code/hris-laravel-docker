<?php

namespace Tests\Feature\Payroll;

use App\Models\Deduction;
use App\Models\Loan;
use App\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * payroll:encrypt-monetary-data
 *
 * Covers: encrypting legacy-plaintext monetary columns (decimal + JSON
 * breakdown columns) in place, ahead of App\Casts\EncryptedDecimal /
 * EncryptedArray being wired onto the payroll models. Rows are seeded via
 * raw DB::table() inserts to mirror actual pre-backfill DB state rather than
 * going through the (still 'float'/'array'-cast) Eloquent models.
 */
class EncryptMonetaryDataCommandTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_encrypts_legacy_plaintext_decimal_columns_and_round_trips_the_value(): void
    {
        $employee = $this->createEmployee();
        $run = PayrollRun::create(['period' => '2026-08-01 to 2026-08-15', 'status' => 'draft']);

        $id = DB::table('payroll_details')->insertGetId([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'basic_salary' => '25000.00',
            'earnings' => '1500.50',
            'deductions' => '0.00',
            'net_pay' => '26500.50',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('payroll:encrypt-monetary-data')->assertExitCode(0);

        $stored = DB::table('payroll_details')->where('id', $id)->first();

        $this->assertNotSame('25000.00', $stored->basic_salary);
        $this->assertSame(25000.0, (float) Crypt::decryptString($stored->basic_salary));
        $this->assertSame(1500.5, (float) Crypt::decryptString($stored->earnings));
        $this->assertSame(26500.5, (float) Crypt::decryptString($stored->net_pay));
    }

    public function test_null_column_is_left_untouched(): void
    {
        $employee = $this->createEmployee();
        $run = PayrollRun::create(['period' => '2026-08-01 to 2026-08-15', 'status' => 'draft']);

        $id = DB::table('payroll_details')->insertGetId([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'basic_salary' => '25000.00',
            'earnings' => '0.00',
            'deductions' => '0.00',
            'net_pay' => '25000.00',
            'other_deductions' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('payroll:encrypt-monetary-data')->assertExitCode(0);

        $stored = DB::table('payroll_details')->where('id', $id)->first();
        $this->assertNull($stored->other_deductions);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $employee = $this->createEmployee();
        $run = PayrollRun::create(['period' => '2026-08-01 to 2026-08-15', 'status' => 'draft']);

        $id = DB::table('payroll_details')->insertGetId([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'basic_salary' => '25000.00',
            'earnings' => '0.00',
            'deductions' => '0.00',
            'net_pay' => '25000.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('payroll:encrypt-monetary-data', ['--dry-run' => true])->assertExitCode(0);

        $stored = DB::table('payroll_details')->where('id', $id)->first();
        $this->assertSame('25000.00', $stored->basic_salary);
    }

    public function test_encrypts_json_breakdown_column_and_round_trips_nested_values(): void
    {
        $id = DB::table('deductions')->insertGetId([
            'type' => 'philhealth',
            'mandatory_config' => json_encode(['rate' => 0.025, 'floor' => 400.00, 'ceiling' => 3750.00]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('payroll:encrypt-monetary-data')->assertExitCode(0);

        $stored = DB::table('deductions')->where('id', $id)->first();
        $decoded = json_decode($stored->mandatory_config, true);

        $this->assertArrayHasKey('encrypted', $decoded);

        $inner = json_decode(Crypt::decryptString($decoded['encrypted']), true);
        $this->assertSame(400.0, (float) $inner['floor']);
        $this->assertSame(3750.0, (float) $inner['ceiling']);
    }

    public function test_rerunning_the_command_does_not_double_encrypt_already_encrypted_rows(): void
    {
        $deduction = Deduction::create(['type' => 'Salary Loan', 'deduction_category' => 'loan', 'provider' => 'LBP']);
        $employee = $this->createEmployee();

        $id = DB::table('loans')->insertGetId([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'balance' => '12000.00',
            'monthly_payment' => '1000.00',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('payroll:encrypt-monetary-data')->assertExitCode(0);
        $afterFirstRun = DB::table('loans')->where('id', $id)->first();

        $this->artisan('payroll:encrypt-monetary-data')->assertExitCode(0);
        $afterSecondRun = DB::table('loans')->where('id', $id)->first();

        // No re-write happened, so ciphertext (which embeds a random IV) is
        // byte-identical, not merely value-equal after decrypting.
        $this->assertSame($afterFirstRun->balance, $afterSecondRun->balance);
        $this->assertSame($afterFirstRun->monthly_payment, $afterSecondRun->monthly_payment);
        $this->assertSame(12000.0, (float) Crypt::decryptString($afterSecondRun->balance));
    }
}
