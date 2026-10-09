<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('campaign_type', 64)->index();
            $table->integer('total_items')->default(0);
            $table->integer('processed_items')->default(0);
            $table->integer('failed_items')->default(0);
            $table->string('status', 32)->default('pending')->index();
            $table->json('payload')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['campaign_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_campaigns');
    }
};
