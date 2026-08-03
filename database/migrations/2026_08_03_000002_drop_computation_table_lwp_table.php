<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('computation_table_lwp');
    }

    public function down(): void
    {
        Schema::create('computation_table_lwp', function (Blueprint $table) {
            $table->unsignedSmallInteger('days_present')->primary();
            $table->decimal('credit_earned', 6, 3);
        });

        DB::table('computation_table_lwp')->insert([
            ['days_present' => 1,  'credit_earned' => 0.042],
            ['days_present' => 2,  'credit_earned' => 0.083],
            ['days_present' => 3,  'credit_earned' => 0.125],
            ['days_present' => 4,  'credit_earned' => 0.167],
            ['days_present' => 5,  'credit_earned' => 0.208],
            ['days_present' => 6,  'credit_earned' => 0.250],
            ['days_present' => 7,  'credit_earned' => 0.292],
            ['days_present' => 8,  'credit_earned' => 0.333],
            ['days_present' => 9,  'credit_earned' => 0.375],
            ['days_present' => 10, 'credit_earned' => 0.417],
            ['days_present' => 11, 'credit_earned' => 0.458],
            ['days_present' => 12, 'credit_earned' => 0.500],
            ['days_present' => 13, 'credit_earned' => 0.542],
            ['days_present' => 14, 'credit_earned' => 0.583],
            ['days_present' => 15, 'credit_earned' => 0.625],
            ['days_present' => 16, 'credit_earned' => 0.667],
            ['days_present' => 17, 'credit_earned' => 0.708],
            ['days_present' => 18, 'credit_earned' => 0.750],
            ['days_present' => 19, 'credit_earned' => 0.792],
            ['days_present' => 20, 'credit_earned' => 0.833],
            ['days_present' => 21, 'credit_earned' => 0.875],
            ['days_present' => 22, 'credit_earned' => 0.917],
            ['days_present' => 23, 'credit_earned' => 0.958],
            ['days_present' => 24, 'credit_earned' => 1.000],
            ['days_present' => 25, 'credit_earned' => 1.042],
            ['days_present' => 26, 'credit_earned' => 1.083],
            ['days_present' => 27, 'credit_earned' => 1.125],
            ['days_present' => 28, 'credit_earned' => 1.167],
            ['days_present' => 29, 'credit_earned' => 1.208],
            ['days_present' => 30, 'credit_earned' => 1.250],
        ]);
    }
};
