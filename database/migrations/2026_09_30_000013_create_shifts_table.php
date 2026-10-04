<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name', 128);
            $table->string('code', 64)->index();
            $table->time('shift_start')->default('09:00:00');
            $table->time('shift_end')->default('18:00:00');
            $table->integer('grace_period_minutes')->default(15);
            $table->integer('early_out_threshold_minutes')->default(30);
            $table->decimal('half_day_threshold_hours', 4, 2)->default(4.0);
            $table->decimal('min_hours_full_day', 4, 2)->default(8.0);
            $table->boolean('is_overnight')->default(false);
            $table->integer('break_duration_minutes')->default(60);
            $table->boolean('is_flexible')->default(false);
            $table->string('color', 32)->default('#3B82F6');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
