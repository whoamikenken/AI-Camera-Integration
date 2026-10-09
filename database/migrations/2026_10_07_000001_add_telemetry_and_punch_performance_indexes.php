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
        // 1. access_logs: composite index to eliminate filesorts on camera telemetry feeds
        if (Schema::hasTable('access_logs')) {
            Schema::table('access_logs', function (Blueprint $table) {
                $table->index(['device_id', 'captured_at'], 'idx_access_logs_device_id_captured_at');
            });
        }

        // 2. attendance_punches: foreign key index on device_id
        if (Schema::hasTable('attendance_punches')) {
            Schema::table('attendance_punches', function (Blueprint $table) {
                $table->index('device_id', 'idx_attendance_punches_device_id');
            });
        }

        // 3. notifications: composite indexes for latest user notifications and unread counts
        if (Schema::hasTable('notifications')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'idx_notifications_notifiable_created_at');
                $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'idx_notifications_notifiable_read_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('notifications')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropIndex('idx_notifications_notifiable_read_at');
                $table->dropIndex('idx_notifications_notifiable_created_at');
            });
        }

        if (Schema::hasTable('attendance_punches')) {
            Schema::table('attendance_punches', function (Blueprint $table) {
                $table->dropIndex('idx_attendance_punches_device_id');
            });
        }

        if (Schema::hasTable('access_logs')) {
            Schema::table('access_logs', function (Blueprint $table) {
                $table->dropIndex('idx_access_logs_device_id_captured_at');
            });
        }
    }
};
