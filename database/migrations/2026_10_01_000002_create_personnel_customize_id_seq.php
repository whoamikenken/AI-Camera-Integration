<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE SEQUENCE IF NOT EXISTS personnel_customize_id_seq START WITH 1000");
            DB::statement("SELECT setval('personnel_customize_id_seq', GREATEST(COALESCE((SELECT MAX(customize_id) FROM personnel), 999) + 1, 1000), false)");
            DB::statement("ALTER TABLE personnel ALTER COLUMN customize_id SET DEFAULT nextval('personnel_customize_id_seq')");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE personnel ALTER COLUMN customize_id DROP DEFAULT");
            DB::statement("DROP SEQUENCE IF EXISTS personnel_customize_id_seq");
        }
    }
};
