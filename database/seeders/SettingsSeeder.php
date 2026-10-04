<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Attendance Group
            [
                'group' => 'attendance',
                'key' => 'attendance.auto_process',
                'value' => '1',
                'type' => 'boolean',
                'description' => 'Automatically pair camera verification punches into daily attendance records',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.late_grace_minutes',
                'value' => '15',
                'type' => 'integer',
                'description' => 'Global grace period in minutes before clock-in is flagged as Late',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.early_out_threshold_minutes',
                'value' => '15',
                'type' => 'integer',
                'description' => 'Threshold in minutes before shift end flagged as Early Out',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.overtime_threshold_minutes',
                'value' => '30',
                'type' => 'integer',
                'description' => 'Minimum minutes worked beyond shift to count towards overtime',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.min_hours_full_day',
                'value' => '8.0',
                'type' => 'float',
                'description' => 'Minimum net work hours required for Full-Day Present status',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.half_day_threshold_hours',
                'value' => '4.0',
                'type' => 'float',
                'description' => 'Hours threshold below which a day is counted as Half-Day',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.weekend_days',
                'value' => json_encode([0, 6]),
                'type' => 'json',
                'description' => 'Designated weekend days (0 = Sunday, 6 = Saturday)',
                'is_public' => false,
            ],
            [
                'group' => 'attendance',
                'key' => 'attendance.punch_debounce_minutes',
                'value' => '3',
                'type' => 'integer',
                'description' => 'Ignore duplicate clock punches within this time window',
                'is_public' => false,
            ],

            // Visitor Management Group
            [
                'group' => 'visitor',
                'key' => 'visitor.require_photo',
                'value' => '1',
                'type' => 'boolean',
                'description' => 'Mandatory visitor facial capture during check-in',
                'is_public' => false,
            ],
            [
                'group' => 'visitor',
                'key' => 'visitor.require_nda',
                'value' => '0',
                'type' => 'boolean',
                'description' => 'Require electronic NDA acknowledgment before badge issuance',
                'is_public' => false,
            ],
            [
                'group' => 'visitor',
                'key' => 'visitor.auto_checkout_time',
                'value' => '23:59:59',
                'type' => 'string',
                'description' => 'Time of day when un-checked-out visits are automatically closed',
                'is_public' => false,
            ],
            [
                'group' => 'visitor',
                'key' => 'visitor.max_visit_duration_hours',
                'value' => '8',
                'type' => 'integer',
                'description' => 'Maximum allowed stay duration for visitor passes',
                'is_public' => false,
            ],
            [
                'group' => 'visitor',
                'key' => 'visitor.enroll_face_to_camera',
                'value' => '1',
                'type' => 'boolean',
                'description' => 'Automatically sync visitor biometric template to edge cameras upon check-in',
                'is_public' => false,
            ],

            // Notification Group
            [
                'group' => 'notification',
                'key' => 'notification.email_enabled',
                'value' => '0',
                'type' => 'boolean',
                'description' => 'Enable outbound SMTP email notifications',
                'is_public' => false,
            ],
            [
                'group' => 'notification',
                'key' => 'notification.sms_enabled',
                'value' => '0',
                'type' => 'boolean',
                'description' => 'Enable SMS alert dispatch',
                'is_public' => false,
            ],
            [
                'group' => 'notification',
                'key' => 'notification.late_alert_enabled',
                'value' => '1',
                'type' => 'boolean',
                'description' => 'Dispatch push/in-app alert to employee upon late arrival',
                'is_public' => false,
            ],
            [
                'group' => 'notification',
                'key' => 'notification.stranger_alert_sound',
                'value' => '1',
                'type' => 'boolean',
                'description' => 'Play audible tone on browser monitor when stranger detected',
                'is_public' => true,
            ],

            // System Group
            [
                'group' => 'system',
                'key' => 'system.company_name',
                'value' => 'Intelligent AI Camera Hub',
                'type' => 'string',
                'description' => 'Organization/System Display Name',
                'is_public' => true,
            ],
            [
                'group' => 'system',
                'key' => 'system.timezone',
                'value' => 'Asia/Manila',
                'type' => 'string',
                'description' => 'Primary system operational timezone',
                'is_public' => true,
            ],
            [
                'group' => 'system',
                'key' => 'system.date_format',
                'value' => 'YYYY-MM-DD',
                'type' => 'string',
                'description' => 'Frontend date display format',
                'is_public' => true,
            ],
            [
                'group' => 'system',
                'key' => 'system.time_format',
                'value' => '24h',
                'type' => 'string',
                'description' => 'Frontend time display format (12h or 24h)',
                'is_public' => true,
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['organization_id' => null, 'key' => $setting['key']],
                $setting
            );
        }
    }
}
