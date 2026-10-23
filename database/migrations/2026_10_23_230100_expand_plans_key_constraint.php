<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The SaaS plan CRUD permits custom slug keys. The enum-like check created
     * in July unintentionally limited PostgreSQL to three predefined plans.
     * Keep the key unique index and enforce the API's accepted slug alphabet.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE plans DROP CONSTRAINT IF EXISTS plans_key_check');
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_key_check CHECK (key ~ '^[a-z0-9][a-z0-9_-]{0,59}$') NOT VALID");
        DB::statement('ALTER TABLE plans VALIDATE CONSTRAINT plans_key_check');
    }

    /**
     * Refuse a destructive rollback once any custom plan exists. Restoring the
     * historical enum would reject legitimate custom plans on PostgreSQL.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (DB::table('plans')->whereNotIn('key', ['basic', 'professional', 'enterprise'])->exists()) {
            throw new RuntimeException(
                'Cannot restore the legacy plans_key_check while custom plans exist. Preserve the plans and apply a forward migration.',
            );
        }

        DB::statement('ALTER TABLE plans DROP CONSTRAINT IF EXISTS plans_key_check');
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_key_check CHECK (key IN ('basic', 'professional', 'enterprise')) NOT VALID");
        DB::statement('ALTER TABLE plans VALIDATE CONSTRAINT plans_key_check');
    }
};
