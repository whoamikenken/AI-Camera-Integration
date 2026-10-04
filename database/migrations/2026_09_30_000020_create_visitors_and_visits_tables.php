<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('first_name', 64);
            $table->string('last_name', 64)->nullable();
            $table->string('email', 128)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('company', 128)->nullable();
            $table->string('id_type', 32)->nullable(); // passport, national_id, driver_license, other
            $table->string('id_number', 64)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->text('block_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_id')->constrained('visitors')->cascadeOnDelete();
            $table->foreignId('host_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->nullOnDelete();
            $table->string('purpose', 64)->default('meeting'); // meeting, interview, delivery, vendor, maintenance, other
            $table->text('purpose_detail')->nullable();
            $table->timestamp('expected_arrival')->nullable();
            $table->timestamp('check_in_time')->nullable();
            $table->timestamp('check_out_time')->nullable();
            $table->string('badge_number', 64)->nullable();
            $table->boolean('nda_signed')->default(false);
            $table->string('status', 32)->default('expected'); // expected, checked_in, checked_out, cancelled, rejected
            $table->timestamps();

            $table->index(['status', 'visitor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
        Schema::dropIfExists('visitors');
    }
};
