<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64);
            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
            $table->string('alert_type', 64)->index(); // PPE_VIOLATION, TRIPWIRE_INCURSION, AREA_INTRUSION, FIRE_SMOKE, TEMPERATURE_HIGH, LEAVE_POST, PARABOLIC_DROP, KITCHEN_HYGIENE, SAFETY_RIDE, GENERIC_ALARM
            $table->string('operator', 64)->nullable(); // e.g. ClothHelmetSnapPush, BehaviorSnapPush, FireSmokeSnapPush
            $table->string('severity', 20)->default('WARNING')->index(); // INFO, WARNING, CRITICAL
            $table->string('title', 128);
            $table->text('description')->nullable();
            $table->string('snap_pic_url', 255)->nullable();
            $table->string('scene_pic_url', 255)->nullable();
            $table->string('video_url', 255)->nullable();
            $table->json('details')->nullable();
            $table->string('status', 20)->default('NEW')->index(); // NEW, ACKNOWLEDGED, RESOLVED, DISMISSED
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('captured_at')->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_alerts');
    }
};
