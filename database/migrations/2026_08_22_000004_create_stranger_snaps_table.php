<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stranger_snaps', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64)->index();
            $table->unsignedBigInteger('snap_id')->nullable()->comment('Device internal snap sequence ID');
            $table->string('snap_pic_url', 255);
            $table->string('scene_pic_url', 255)->nullable();
            $table->json('target_pos')->nullable()->comment('Bounding box coordinates in scene');
            $table->integer('is_no_mask')->default(0)->comment('0: Mask ok/disabled, 1: No mask, 2: No mask allowed');
            $table->string('alarm_action', 128)->nullable()->comment('Specific violation action if applicable');
            $table->timestampTz('captured_at')->index();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stranger_snaps');
    }
};
