<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->string('native', 64)->nullable()->after('address');
            $table->text('notes')->nullable()->after('native');
            $table->string('mj_card_no', 64)->nullable()->after('notes');
            $table->integer('mj_card_from')->nullable()->after('mj_card_no');
        });
    }

    public function down(): void
    {
        Schema::table('personnel', function (Blueprint $table) {
            $table->dropColumn(['native', 'notes', 'mj_card_no', 'mj_card_from']);
        });
    }
};
