<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'otp_challenges_actor_context_check' => 'otp_challenges',
            'authentication_sessions_actor_context_check' => 'authentication_sessions',
        ] as $constraint => $table) {
            DB::statement(sprintf('ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS "%s"', $table, $constraint));
            DB::statement(sprintf(
                'ALTER TABLE "%s" ADD CONSTRAINT "%s" CHECK (actor_context IN (\'patient\', \'staff\', \'clinic_admin\', \'receptionist\', \'doctor\'))',
                $table,
                $constraint,
            ));
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'otp_challenges_actor_context_check' => 'otp_challenges',
            'authentication_sessions_actor_context_check' => 'authentication_sessions',
        ] as $constraint => $table) {
            DB::statement(sprintf('ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS "%s"', $table, $constraint));
            DB::statement(sprintf(
                'ALTER TABLE "%s" ADD CONSTRAINT "%s" CHECK (actor_context IN (\'patient\', \'staff\', \'clinic_admin\', \'doctor\'))',
                $table,
                $constraint,
            ));
        }
    }
};
