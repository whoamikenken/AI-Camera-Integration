<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64)->index();
            $table->unsignedBigInteger('personnel_id')->index();
            $table->string('action', 32)->comment('ADD, EDIT, DELETE');
            $table->string('status', 20)->default('PENDING')->index()->comment('PENDING, PROCESSING, COMPLETED, FAILED');
            $table->integer('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->foreign('device_id')->references('device_id')->on('devices')->onDelete('cascade');
            $table->foreign('personnel_id')->references('id')->on('personnel')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_tasks');
    }
};
