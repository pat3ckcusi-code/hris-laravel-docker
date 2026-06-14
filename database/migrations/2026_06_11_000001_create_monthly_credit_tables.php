<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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

        Schema::create('computation_table_wop', function (Blueprint $table) {
            $table->decimal('abs_wop', 4, 2)->primary();
            $table->decimal('vl_earned', 6, 3);
        });

        DB::table('computation_table_wop')->insert([
            ['abs_wop' =>  0.00, 'vl_earned' => 1.250],
            ['abs_wop' =>  0.50, 'vl_earned' => 1.229],
            ['abs_wop' =>  1.00, 'vl_earned' => 1.208],
            ['abs_wop' =>  1.50, 'vl_earned' => 1.188],
            ['abs_wop' =>  2.00, 'vl_earned' => 1.167],
            ['abs_wop' =>  2.50, 'vl_earned' => 1.146],
            ['abs_wop' =>  3.00, 'vl_earned' => 1.125],
            ['abs_wop' =>  3.50, 'vl_earned' => 1.104],
            ['abs_wop' =>  4.00, 'vl_earned' => 1.083],
            ['abs_wop' =>  4.50, 'vl_earned' => 1.063],
            ['abs_wop' =>  5.00, 'vl_earned' => 1.042],
            ['abs_wop' =>  5.50, 'vl_earned' => 1.021],
            ['abs_wop' =>  6.00, 'vl_earned' => 1.000],
            ['abs_wop' =>  6.50, 'vl_earned' => 0.979],
            ['abs_wop' =>  7.00, 'vl_earned' => 0.958],
            ['abs_wop' =>  7.50, 'vl_earned' => 0.938],
            ['abs_wop' =>  8.00, 'vl_earned' => 0.917],
            ['abs_wop' =>  8.50, 'vl_earned' => 0.896],
            ['abs_wop' =>  9.00, 'vl_earned' => 0.875],
            ['abs_wop' =>  9.50, 'vl_earned' => 0.854],
            ['abs_wop' => 10.00, 'vl_earned' => 0.833],
            ['abs_wop' => 10.50, 'vl_earned' => 0.813],
            ['abs_wop' => 11.00, 'vl_earned' => 0.792],
            ['abs_wop' => 11.50, 'vl_earned' => 0.771],
            ['abs_wop' => 12.00, 'vl_earned' => 0.750],
            ['abs_wop' => 12.50, 'vl_earned' => 0.729],
            ['abs_wop' => 13.00, 'vl_earned' => 0.708],
            ['abs_wop' => 13.50, 'vl_earned' => 0.687],
            ['abs_wop' => 14.00, 'vl_earned' => 0.667],
            ['abs_wop' => 14.50, 'vl_earned' => 0.646],
            ['abs_wop' => 15.00, 'vl_earned' => 0.625],
            ['abs_wop' => 15.50, 'vl_earned' => 0.604],
            ['abs_wop' => 16.00, 'vl_earned' => 0.583],
            ['abs_wop' => 16.50, 'vl_earned' => 0.562],
            ['abs_wop' => 17.00, 'vl_earned' => 0.542],
            ['abs_wop' => 17.50, 'vl_earned' => 0.521],
            ['abs_wop' => 18.00, 'vl_earned' => 0.500],
            ['abs_wop' => 18.50, 'vl_earned' => 0.479],
            ['abs_wop' => 19.00, 'vl_earned' => 0.458],
            ['abs_wop' => 19.50, 'vl_earned' => 0.437],
            ['abs_wop' => 20.00, 'vl_earned' => 0.417],
            ['abs_wop' => 20.50, 'vl_earned' => 0.396],
            ['abs_wop' => 21.00, 'vl_earned' => 0.375],
            ['abs_wop' => 21.50, 'vl_earned' => 0.354],
            ['abs_wop' => 22.00, 'vl_earned' => 0.333],
            ['abs_wop' => 22.50, 'vl_earned' => 0.312],
            ['abs_wop' => 23.00, 'vl_earned' => 0.292],
            ['abs_wop' => 23.50, 'vl_earned' => 0.271],
            ['abs_wop' => 24.00, 'vl_earned' => 0.250],
            ['abs_wop' => 24.50, 'vl_earned' => 0.229],
            ['abs_wop' => 25.00, 'vl_earned' => 0.208],
            ['abs_wop' => 25.50, 'vl_earned' => 0.187],
            ['abs_wop' => 26.00, 'vl_earned' => 0.167],
            ['abs_wop' => 26.50, 'vl_earned' => 0.146],
            ['abs_wop' => 27.00, 'vl_earned' => 0.125],
            ['abs_wop' => 27.50, 'vl_earned' => 0.104],
            ['abs_wop' => 28.00, 'vl_earned' => 0.083],
            ['abs_wop' => 28.50, 'vl_earned' => 0.062],
            ['abs_wop' => 29.00, 'vl_earned' => 0.042],
            ['abs_wop' => 29.50, 'vl_earned' => 0.021],
        ]);

        Schema::create('monthly_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('days_present', 5, 3)->default(0);
            $table->decimal('abs_wop_days', 5, 3)->default(0);
            $table->decimal('computed_vl', 6, 3)->nullable();
            $table->decimal('computed_sl', 6, 3)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['user_id', 'year', 'month']);
            $table->timestamps();
        });

        Schema::create('leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('transaction_date');
            $table->string('transaction_type', 30);
            $table->string('leave_type', 30);
            $table->decimal('days_present', 5, 3)->nullable();
            $table->decimal('abs_wop_days', 5, 3)->nullable();
            $table->decimal('debit_vl', 6, 3)->default(0);
            $table->decimal('debit_sl', 6, 3)->default(0);
            $table->decimal('credit_vl', 6, 3)->default(0);
            $table->decimal('credit_sl', 6, 3)->default(0);
            $table->decimal('vl_balance_after', 8, 3);
            $table->decimal('sl_balance_after', 8, 3);
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_type', 30)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index('transaction_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_ledger');
        Schema::dropIfExists('monthly_attendance');
        Schema::dropIfExists('computation_table_wop');
        Schema::dropIfExists('computation_table_lwp');
    }
};
