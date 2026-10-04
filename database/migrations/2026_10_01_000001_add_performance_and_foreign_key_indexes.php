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
        // 1. device_alerts table
        if (Schema::hasTable('device_alerts')) {
            Schema::table('device_alerts', function (Blueprint $table) {
                $table->index('device_id', 'device_alerts_device_id_index');
                $table->index(['captured_at', 'severity'], 'device_alerts_captured_at_severity_index');
            });
        }

        // 2. visits table
        if (Schema::hasTable('visits')) {
            Schema::table('visits', function (Blueprint $table) {
                $table->index('host_employee_id', 'visits_host_employee_id_index');
                $table->index('personnel_id', 'visits_personnel_id_index');
                $table->index('check_in_time', 'visits_check_in_time_index');
                $table->index('check_out_time', 'visits_check_out_time_index');
            });
        }

        // 3. visitors table
        if (Schema::hasTable('visitors')) {
            Schema::table('visitors', function (Blueprint $table) {
                $table->index('email', 'visitors_email_index');
                $table->index('phone', 'visitors_phone_index');
            });
        }

        // 4. employees table
        if (Schema::hasTable('employees')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->index('designation_id', 'employees_designation_id_index');
                $table->index('location_id', 'employees_location_id_index');
                $table->index('shift_id', 'employees_shift_id_index');
                $table->index('reporting_manager_id', 'employees_reporting_manager_id_index');
            });
        }

        // 5. leave_requests table
        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->index(['employee_id', 'status', 'start_date', 'end_date'], 'leave_requests_emp_status_dates_index');
                $table->index('status', 'leave_requests_status_index');
            });
        }

        // 6. access_logs table
        if (Schema::hasTable('access_logs')) {
            Schema::table('access_logs', function (Blueprint $table) {
                $table->index(['captured_at', 'verify_status'], 'access_logs_captured_at_verify_status_index');
                $table->index(['customize_id', 'captured_at'], 'access_logs_customize_id_captured_at_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('access_logs')) {
            Schema::table('access_logs', function (Blueprint $table) {
                $table->dropIndex('access_logs_customize_id_captured_at_index');
                $table->dropIndex('access_logs_captured_at_verify_status_index');
            });
        }

        if (Schema::hasTable('leave_requests')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropIndex('leave_requests_status_index');
                $table->dropIndex('leave_requests_emp_status_dates_index');
            });
        }

        if (Schema::hasTable('employees')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropIndex('employees_reporting_manager_id_index');
                $table->dropIndex('employees_shift_id_index');
                $table->dropIndex('employees_location_id_index');
                $table->dropIndex('employees_designation_id_index');
            });
        }

        if (Schema::hasTable('visitors')) {
            Schema::table('visitors', function (Blueprint $table) {
                $table->dropIndex('visitors_phone_index');
                $table->dropIndex('visitors_email_index');
            });
        }

        if (Schema::hasTable('visits')) {
            Schema::table('visits', function (Blueprint $table) {
                $table->dropIndex('visits_check_out_time_index');
                $table->dropIndex('visits_check_in_time_index');
                $table->dropIndex('visits_personnel_id_index');
                $table->dropIndex('visits_host_employee_id_index');
            });
        }

        if (Schema::hasTable('device_alerts')) {
            Schema::table('device_alerts', function (Blueprint $table) {
                $table->dropIndex('device_alerts_captured_at_severity_index');
                $table->dropIndex('device_alerts_device_id_index');
            });
        }
    }
};
