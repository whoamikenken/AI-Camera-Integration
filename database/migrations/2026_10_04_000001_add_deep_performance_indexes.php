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
        Schema::table('visits', function (Blueprint $table) {
            $table->index(['expected_arrival', 'status'], 'idx_visits_expected_arrival_status');
        });

        Schema::table('stranger_snaps', function (Blueprint $table) {
            $table->index(['device_id', 'captured_at'], 'idx_stranger_snaps_device_id_captured_at');
        });

        Schema::table('sync_tasks', function (Blueprint $table) {
            $table->index(['device_id', 'updated_at'], 'idx_sync_tasks_device_id_updated_at');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->index(['date', 'status'], 'idx_attendance_records_date_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex('idx_visits_expected_arrival_status');
        });

        Schema::table('stranger_snaps', function (Blueprint $table) {
            $table->dropIndex('idx_stranger_snaps_device_id_captured_at');
        });

        Schema::table('sync_tasks', function (Blueprint $table) {
            $table->dropIndex('idx_sync_tasks_device_id_updated_at');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_records_date_status');
        });
    }
};
