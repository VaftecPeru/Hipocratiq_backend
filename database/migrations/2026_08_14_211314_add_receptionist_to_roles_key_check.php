<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Forward-only: adds the receptionist role key to the frozen check
     * constraint defined in add_enum_check_constraints.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE roles DROP CONSTRAINT roles_key_check');

        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_key_check CHECK (key IN ('super_admin', 'clinic_admin', 'receptionist', 'doctor'))");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE roles DROP CONSTRAINT roles_key_check');

        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_key_check CHECK (key IN ('super_admin', 'clinic_admin', 'doctor'))");
    }
};
