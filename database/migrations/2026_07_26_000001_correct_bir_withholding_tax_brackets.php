<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The brackets seeded by 2026_07_25_000010 used the wrong boundaries
 * (10417/16667/33333/83333/333333 instead of the real TRAIN-law monthly
 * thresholds: 20833/33333/66667/166667/666667). That migration is
 * idempotent (only inserts if the `bir` row doesn't already exist), so it
 * has no effect on an already-migrated database - this migration corrects
 * the live row's mandatory_config directly, guarded so a Payroll Manager
 * who already customized BIR's brackets via the Rate Configuration UI is
 * never silently overwritten.
 */
return new class extends Migration
{
    private function oldBrackets(): array
    {
        return [
            ['min' => 0,      'max' => 10417,  'base' => 0.00,      'rate' => 0.00],
            ['min' => 10417,  'max' => 16667,  'base' => 0.00,      'rate' => 0.15],
            ['min' => 16667,  'max' => 33333,  'base' => 937.50,    'rate' => 0.20],
            ['min' => 33333,  'max' => 83333,  'base' => 4270.83,   'rate' => 0.25],
            ['min' => 83333,  'max' => 333333, 'base' => 16770.83,  'rate' => 0.30],
            ['min' => 333333, 'max' => null,   'base' => 91770.83,  'rate' => 0.35],
        ];
    }

    private function newBrackets(): array
    {
        return [
            ['min' => 0,      'max' => 20833,  'base' => 0.00,      'rate' => 0.00],
            ['min' => 20833,  'max' => 33333,  'base' => 0.00,      'rate' => 0.15],
            ['min' => 33333,  'max' => 66667,  'base' => 1875.00,   'rate' => 0.20],
            ['min' => 66667,  'max' => 166667, 'base' => 8541.80,   'rate' => 0.25],
            ['min' => 166667, 'max' => 666667, 'base' => 33541.80,  'rate' => 0.30],
            ['min' => 666667, 'max' => null,   'base' => 183541.80, 'rate' => 0.35],
        ];
    }

    public function up(): void
    {
        $bir = DB::table('deductions')->where('mandatory_key', 'bir')->first();

        if ($bir && (json_decode($bir->mandatory_config, true)['brackets'] ?? null) == $this->oldBrackets()) {
            DB::table('deductions')->where('mandatory_key', 'bir')->update([
                'mandatory_config' => json_encode(['brackets' => $this->newBrackets()]),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $bir = DB::table('deductions')->where('mandatory_key', 'bir')->first();

        if ($bir && (json_decode($bir->mandatory_config, true)['brackets'] ?? null) == $this->newBrackets()) {
            DB::table('deductions')->where('mandatory_key', 'bir')->update([
                'mandatory_config' => json_encode(['brackets' => $this->oldBrackets()]),
                'updated_at' => now(),
            ]);
        }
    }
};
