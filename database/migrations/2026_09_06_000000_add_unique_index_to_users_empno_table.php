<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closes a concurrent-create race: two simultaneous employee creations
     * (single-form or bulk import) that both auto-generate the same sequential
     * EmpNo previously both succeeded, producing duplicate EmpNo values with no
     * DB-level backstop - only a Rule::unique validation check per request,
     * which two simultaneous requests can both pass before either commits.
     *
     * Confirmed safe to add directly: a check of the real dev database found
     * zero non-null EmpNo collisions among 1,852 users (the only rows sharing
     * a value were 11 legitimately NULL - MySQL/InnoDB's UNIQUE index already
     * permits multiple NULLs to coexist, so no data cleanup or column
     * nullability change is needed here).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('EmpNo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['EmpNo']);
        });
    }
};
