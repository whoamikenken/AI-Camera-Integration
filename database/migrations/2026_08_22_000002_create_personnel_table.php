<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customize_id')->unique()->index();
            $table->uuid('person_uuid')->unique()->index();
            $table->string('name', 64)->index();
            $table->integer('person_type')->default(0)->index()->comment('0: Whitelist, 1: Blacklist');
            $table->integer('gender')->default(0)->comment('0: Male, 1: Female');
            $table->string('id_card', 32)->nullable();
            $table->string('tel_num', 32)->nullable();
            $table->string('address', 128)->nullable();
            $table->date('birthday')->nullable();
            $table->integer('temp_valid')->default(0)->comment('0: Permanent, 1: Temporary');
            $table->timestampTz('valid_begin')->nullable();
            $table->timestampTz('valid_end')->nullable();
            $table->integer('effect_number')->default(1)->comment('-1: Infinite, 1-10000: Finite passes');
            $table->string('photo_path', 255)->nullable();
            $table->longText('photo_base64')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel');
    }
};
