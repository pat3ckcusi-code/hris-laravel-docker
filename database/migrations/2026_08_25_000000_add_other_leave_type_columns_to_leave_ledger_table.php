<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * leave_ledger previously only had debit/credit columns for VL and SL, so a leave
     * transaction touching WLNS/SPL/CTO/SP (all real leave_balances columns) had no
     * ledger record at all for that portion. These 8 columns are purely additive
     * record-keeping — no running-balance chain (vl_balance_after/sl_balance_after)
     * exists for these types, matching how the rest of the app treats them (flat
     * balances set directly, no monthly accrual, no CSC Leave Card column).
     */
    public function up(): void
    {
        Schema::table('leave_ledger', function (Blueprint $table) {
            $table->decimal('debit_wlns', 6, 3)->default(0)->after('credit_sl');
            $table->decimal('credit_wlns', 6, 3)->default(0)->after('debit_wlns');
            $table->decimal('debit_spl', 6, 3)->default(0)->after('credit_wlns');
            $table->decimal('credit_spl', 6, 3)->default(0)->after('debit_spl');
            $table->decimal('debit_cto', 6, 3)->default(0)->after('credit_spl');
            $table->decimal('credit_cto', 6, 3)->default(0)->after('debit_cto');
            $table->decimal('debit_sp', 6, 3)->default(0)->after('credit_cto');
            $table->decimal('credit_sp', 6, 3)->default(0)->after('debit_sp');
        });
    }

    public function down(): void
    {
        Schema::table('leave_ledger', function (Blueprint $table) {
            $table->dropColumn([
                'debit_wlns', 'credit_wlns',
                'debit_spl', 'credit_spl',
                'debit_cto', 'credit_cto',
                'debit_sp', 'credit_sp',
            ]);
        });
    }
};
