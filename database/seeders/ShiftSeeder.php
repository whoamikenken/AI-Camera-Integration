<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Shift;
use Illuminate\Database\Seeder;

class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::first();
        $orgId = $org?->id;

        $shifts = [
            [
                'organization_id' => $orgId,
                'name' => 'Standard Day Shift',
                'code' => 'SHIFT-DAY',
                'shift_start' => '09:00:00',
                'shift_end' => '18:00:00',
                'grace_period_minutes' => 15,
                'early_out_threshold_minutes' => 30,
                'half_day_threshold_hours' => 4.0,
                'min_hours_full_day' => 8.0,
                'is_overnight' => false,
                'break_duration_minutes' => 60,
                'is_flexible' => false,
                'color' => '#3B82F6',
                'is_active' => true,
            ],
            [
                'organization_id' => $orgId,
                'name' => 'Night Shift',
                'code' => 'SHIFT-NIGHT',
                'shift_start' => '22:00:00',
                'shift_end' => '07:00:00',
                'grace_period_minutes' => 15,
                'early_out_threshold_minutes' => 30,
                'half_day_threshold_hours' => 4.0,
                'min_hours_full_day' => 8.0,
                'is_overnight' => true,
                'break_duration_minutes' => 60,
                'is_flexible' => false,
                'color' => '#8B5CF6',
                'is_active' => true,
            ],
            [
                'organization_id' => $orgId,
                'name' => 'Flexible Shift',
                'code' => 'SHIFT-FLEX',
                'shift_start' => '09:00:00',
                'shift_end' => '18:00:00',
                'grace_period_minutes' => 0,
                'early_out_threshold_minutes' => 0,
                'half_day_threshold_hours' => 4.0,
                'min_hours_full_day' => 8.0,
                'is_overnight' => false,
                'break_duration_minutes' => 60,
                'is_flexible' => true,
                'color' => '#10B981',
                'is_active' => true,
            ],
        ];

        foreach ($shifts as $s) {
            Shift::firstOrCreate(
                ['code' => $s['code']],
                $s
            );
        }
    }
}
