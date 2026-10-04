<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnel_id')->nullable()->unique()->constrained('personnel')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('reporting_manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->string('employee_code', 64)->unique();
            $table->string('first_name', 64);
            $table->string('last_name', 64)->nullable();
            $table->string('employment_type', 32)->default('full-time');
            $table->string('employment_status', 32)->default('active');
            $table->date('date_of_joining')->nullable();
            $table->date('date_of_leaving')->nullable();
            $table->string('work_email', 128)->nullable()->unique();
            $table->string('personal_email', 128)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('avatar', 255)->nullable();
            $table->string('emergency_contact_name', 128)->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('employment_status');
            $table->index('department_id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
