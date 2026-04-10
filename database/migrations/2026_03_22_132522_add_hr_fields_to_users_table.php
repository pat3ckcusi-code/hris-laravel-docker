<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('EmpNo')->nullable()->after('id');
            $table->string('UserName')->nullable()->after('EmpNo');
            $table->string('AcctName')->nullable()->after('UserName');
            $table->string('designation')->nullable()->after('AcctName');
            $table->unsignedBigInteger('Dept_id')->nullable()->after('designation');
            $table->string('Status')->nullable()->after('password');
            $table->string('ContactNo')->nullable()->after('Status');
            $table->string('access_level')->nullable()->after('ContactNo');

            $table->foreign('Dept_id')->references('Dept_id')->on('departments')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['Dept_id']);
            $table->dropColumn([
                'EmpNo',
                'UserName',
                'AcctName',
                'designation',
                'Dept_id',
                'Status',
                'ContactNo',
                'access_level',
            ]);
        });
    }
};
