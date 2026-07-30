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

        DB::statement(
            'CREATE INDEX clinic_subscriptions_clinic_starts_id_idx
             ON clinic_subscriptions (clinic_id, starts_at DESC, id DESC)',
        );
        DB::statement(
            'CREATE INDEX subscription_payments_subscription_paid_id_idx
             ON subscription_payments (clinic_subscription_id, paid_at DESC, id DESC)
             WHERE status = \'succeeded\' AND paid_at IS NOT NULL',
        );
        DB::statement(
            'CREATE INDEX patient_profiles_clinic_id_idx
             ON patient_profiles (clinic_id)
             WHERE clinic_id IS NOT NULL',
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS patient_profiles_clinic_id_idx');
        DB::statement('DROP INDEX IF EXISTS subscription_payments_subscription_paid_id_idx');
        DB::statement('DROP INDEX IF EXISTS clinic_subscriptions_clinic_starts_id_idx');
    }
};
