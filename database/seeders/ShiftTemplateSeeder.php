<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Seeder;

class ShiftTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            ['name' => 'Morning (2-punch)', 'time_in' => '04:00', 'time_out' => '12:00'],
            ['name' => 'Afternoon (2-punch)', 'time_in' => '13:00', 'time_out' => '20:00'],
            ['name' => '24-Hour Duty (2-punch)', 'time_in' => '08:00', 'time_out' => '08:00'],
        ];

        foreach ($templates as $t) {
            Shift::updateOrCreate(
                ['name' => $t['name']],
                [
                    'time_in' => $t['time_in'],
                    'time_out' => $t['time_out'],
                    'break_out' => null,
                    'break_in' => null,
                    'no_break' => true,
                    'crosses_midnight' => Shift::isCrossMidnight($t['time_in'], $t['time_out']),
                    'is_active' => true,
                ]
            );
        }
    }
}
