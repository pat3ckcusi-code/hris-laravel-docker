<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('department_head_id')->nullable()->after('EmpNo')->constrained('users')->nullOnDelete();
            $table->foreignId('admin_officer_id')->nullable()->after('ao_emp_no')->constrained('users')->nullOnDelete();
        });

        // Backfill: only carry over EmpNo/ao_emp_no matches whose user still holds the
        // expected role. A stale or wrong match must not be propagated into the new FK.
        $departments = DB::table('departments')->get(['Dept_id', 'EmpNo', 'ao_emp_no']);

        foreach ($departments as $dept) {
            $updates = [];

            if (! empty($dept->EmpNo) && $dept->EmpNo !== 'UNASSIGNED') {
                $head = DB::table('users')->where('EmpNo', $dept->EmpNo)->first(['id', 'access_level']);
                if ($head && $this->normalizeRole($head->access_level) === 'department head') {
                    $updates['department_head_id'] = $head->id;
                }
            }

            if (! empty($dept->ao_emp_no)) {
                $ao = DB::table('users')->where('EmpNo', $dept->ao_emp_no)->first(['id', 'access_level']);
                if ($ao && $this->normalizeRole($ao->access_level) === 'administrative officer') {
                    $updates['admin_officer_id'] = $ao->id;
                }
            }

            if (! empty($updates)) {
                DB::table('departments')->where('Dept_id', $dept->Dept_id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['department_head_id']);
            $table->dropForeign(['admin_officer_id']);
            $table->dropColumn(['department_head_id', 'admin_officer_id']);
        });
    }

    private function normalizeRole(?string $role): string
    {
        return strtolower(trim(str_replace(['-', '_'], ' ', (string) $role)));
    }
};
