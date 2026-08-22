<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64)->unique()->index();
            $table->string('name', 128);
            $table->string('ip_address', 45);
            $table->integer('port')->default(8080);
            $table->string('username', 64)->default('admin');
            $table->string('password', 64)->default('admin');
            $table->integer('device_type')->default(0)->comment('0: IPC, 1: DVR, 2: NVR, 3: Panel Unit');
            $table->string('mqtt_topic', 128)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampTz('last_heartbeat_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
