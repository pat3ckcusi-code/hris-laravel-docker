<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('last_name')->nullable()->after('name');
            $table->string('first_name')->nullable()->after('last_name');
            $table->string('middle_name')->nullable()->after('first_name');
        });

        DB::table('users')
            ->select('id', 'name')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $nameParts = $this->splitEmployeeName((string) $user->name);

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'last_name' => $nameParts['last_name'] !== '' ? $nameParts['last_name'] : null,
                            'first_name' => $nameParts['first_name'] !== '' ? $nameParts['first_name'] : null,
                            'middle_name' => $nameParts['middle_name'] !== '' ? $nameParts['middle_name'] : null,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_name', 'first_name', 'middle_name']);
        });
    }

    /**
     * @return array{last_name:string, first_name:string, middle_name:string}
     */
    private function splitEmployeeName(string $fullName): array
    {
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? $fullName);

        if ($fullName === '') {
            return [
                'last_name' => '',
                'first_name' => '',
                'middle_name' => '',
            ];
        }

        if (str_contains($fullName, ',')) {
            [$lastName, $remainingName] = array_pad(array_map('trim', explode(',', $fullName, 2)), 2, '');
            $remainingParts = preg_split('/\s+/', $remainingName, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return [
                'last_name' => $lastName,
                'first_name' => $remainingParts[0] ?? '',
                'middle_name' => implode(' ', array_slice($remainingParts, 1)),
            ];
        }

        $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) === 1) {
            return [
                'last_name' => '',
                'first_name' => $parts[0],
                'middle_name' => '',
            ];
        }

        $firstName = array_shift($parts) ?? '';
        $lastName = array_pop($parts) ?? '';

        return [
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => implode(' ', $parts),
        ];
    }
};