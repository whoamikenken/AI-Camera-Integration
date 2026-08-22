<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64)->index();
            $table->unsignedBigInteger('person_id')->nullable()->comment('Device internal person ID');
            $table->unsignedBigInteger('customize_id')->nullable()->index();
            $table->uuid('person_uuid')->nullable()->index();
            $table->string('person_name', 64)->nullable()->index();
            $table->integer('verify_status')->index()->comment('1: Allowed, 2: Rejected, 3: Not Registered');
            $table->integer('verify_type')->default(1)->comment('1: Whitelist, 2: ID Card, 3: Card+Face');
            $table->integer('person_type')->default(0)->comment('0: Whitelist, 1: Blacklist');
            $table->decimal('similarity', 5, 2)->nullable()->comment('Similarity score 0.00 to 100.00');
            $table->string('snap_pic_url', 255)->nullable();
            $table->string('scene_pic_url', 255)->nullable();
            $table->json('target_pos')->nullable()->comment('Bounding box coordinates in scene');
            $table->integer('is_no_mask')->default(0)->comment('0: Mask ok/disabled, 1: No mask, 2: No mask allowed');
            $table->timestampTz('captured_at')->index();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
    }
};
