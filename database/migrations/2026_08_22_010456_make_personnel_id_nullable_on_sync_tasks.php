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
        Schema::table('sync_tasks', function (Blueprint $table) {
            $table->dropForeign(['personnel_id']);
            $table->unsignedBigInteger('personnel_id')->nullable()->change();
            $table->foreign('personnel_id')->references('id')->on('personnel')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_tasks', function (Blueprint $table) {
            $table->dropForeign(['personnel_id']);
            $table->unsignedBigInteger('personnel_id')->nullable(false)->change();
            $table->foreign('personnel_id')->references('id')->on('personnel')->onDelete('cascade');
        });
    }
};
