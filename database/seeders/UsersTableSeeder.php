<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsersTableSeeder extends Seeder
{
    public function run()
    {
        $accessLevels = [
            'employee',
            'department head',
            'administrative officer',
            'hr manager',
            'mayor',
            'leave manager',
            'time keeper',
            'payroll manager',
            'records manager',
            'front desk',
        ];

        foreach ($accessLevels as $level) {
            DB::table('users')->insert([
                'name' => ucfirst($level) . ' User',
                'email' => str_replace(' ', '_', $level) . '@example.com',
                'password' => Hash::make('password123'),
                'access_level' => $level,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
