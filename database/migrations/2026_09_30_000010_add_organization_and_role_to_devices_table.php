<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->nullOnDelete();

            $table->foreignId('location_id')
                ->nullable()
                ->constrained('locations')
                ->nullOnDelete();

            $table->string('device_role', 32)
                ->default('bidirectional');

            $table->json('department_ids')
                ->nullable();

            $table->index(['organization_id', 'location_id']);
            $table->index('device_role');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropForeign(['location_id']);
            $table->dropColumn(['organization_id', 'location_id', 'device_role', 'department_ids']);
        });
    }
};
