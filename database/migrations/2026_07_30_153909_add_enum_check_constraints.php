<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * These literals are intentionally frozen in the migration. Future enum
     * changes must add a new forward-only migration.
     *
     * @var array<string, array{table: string, expression: string}>
     */
    private const array Constraints = [
        'users_status_check' => [
            'table' => 'users',
            'expression' => "status IN ('pending_verification', 'active', 'suspended', 'disabled')",
        ],
        'users_onboarding_status_check' => [
            'table' => 'users',
            'expression' => "onboarding_status IN ('phone_verification', 'plan_selection', 'completed')",
        ],
        'otp_challenges_purpose_check' => [
            'table' => 'otp_challenges',
            'expression' => "purpose IN ('registration', 'login', 'password_reset')",
        ],
        'otp_challenges_client_type_check' => [
            'table' => 'otp_challenges',
            'expression' => "client_type IN ('web', 'mobile')",
        ],
        'otp_challenges_actor_context_check' => [
            'table' => 'otp_challenges',
            'expression' => "actor_context IN ('patient', 'staff', 'clinic_admin', 'doctor')",
        ],
        'otp_challenges_delivery_status_check' => [
            'table' => 'otp_challenges',
            'expression' => "delivery_status IN ('pending', 'sent', 'failed')",
        ],
        'authentication_sessions_actor_context_check' => [
            'table' => 'authentication_sessions',
            'expression' => "actor_context IN ('patient', 'staff', 'clinic_admin', 'doctor')",
        ],
        'authentication_events_outcome_check' => [
            'table' => 'authentication_events',
            'expression' => "outcome IN ('success', 'failure')",
        ],
        'roles_key_check' => [
            'table' => 'roles',
            'expression' => "key IN ('super_admin', 'clinic_admin', 'doctor')",
        ],
        'roles_scope_check' => [
            'table' => 'roles',
            'expression' => "scope IN ('global', 'organization')",
        ],
        'plans_key_check' => [
            'table' => 'plans',
            'expression' => "key IN ('basic', 'professional', 'enterprise')",
        ],
        'clinics_status_check' => [
            'table' => 'clinics',
            'expression' => "status IN ('active', 'suspended')",
        ],
        'clinic_memberships_status_check' => [
            'table' => 'clinic_memberships',
            'expression' => "status IN ('active', 'suspended')",
        ],
        'clinic_subscriptions_billing_cycle_check' => [
            'table' => 'clinic_subscriptions',
            'expression' => "billing_cycle IN ('monthly', 'annual')",
        ],
        'clinic_subscriptions_status_check' => [
            'table' => 'clinic_subscriptions',
            'expression' => "status IN ('active', 'past_due', 'canceled', 'expired')",
        ],
        'doctor_profiles_practice_type_check' => [
            'table' => 'doctor_profiles',
            'expression' => "practice_type IN ('clinic', 'independent')",
        ],
        'doctor_subscriptions_billing_cycle_check' => [
            'table' => 'doctor_subscriptions',
            'expression' => "billing_cycle IN ('monthly', 'annual')",
        ],
        'doctor_subscriptions_status_check' => [
            'table' => 'doctor_subscriptions',
            'expression' => "status IN ('active', 'past_due', 'canceled', 'expired')",
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::Constraints as $name => $constraint) {
            DB::statement(sprintf(
                'ALTER TABLE "%s" ADD CONSTRAINT "%s" CHECK (%s) NOT VALID',
                $constraint['table'],
                $name,
                $constraint['expression'],
            ));
            DB::statement(sprintf(
                'ALTER TABLE "%s" VALIDATE CONSTRAINT "%s"',
                $constraint['table'],
                $name,
            ));
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(self::Constraints, true) as $name => $constraint) {
            DB::statement(sprintf(
                'ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS "%s"',
                $constraint['table'],
                $name,
            ));
        }
    }
};
