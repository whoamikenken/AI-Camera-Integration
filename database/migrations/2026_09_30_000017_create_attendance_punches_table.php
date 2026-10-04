<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('access_log_id')->nullable()->constrained('access_logs')->nullOnDelete();
            $table->string('device_id', 64)->nullable();
            $table->timestamp('punch_time')->index();
            $table->string('direction', 16)->default('unknown'); // in, out, unknown
            $table->string('source', 32)->default('camera_auto'); // camera_auto, manual, kiosk, mobile
            $table->text('reason')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'punch_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
