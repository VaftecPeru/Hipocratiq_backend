-- HipocratiQ PostgreSQL DDL snapshot.
--
-- This file is derived from the active Laravel migration source in
-- back_api/database/migrations. It creates schema objects only: it does not
-- insert business data, PII, credentials, seed data, or runtime tables that
-- have no migration source.
--
-- Laravel/PostgreSQL mappings used here:
--   * $table->id() becomes bigserial.
--   * foreignId and unsigned integer declarations become PostgreSQL integer
--     types; PostgreSQL does not retain the Laravel unsigned modifier.
--   * timestamp declarations use precision 0 and no time zone.
--   * enum-like values are represented by the migration-authored CHECK
--     constraints; no custom PostgreSQL enum types are defined by the source.
--   * The final state of migrations that drop and recreate a foreign key or
--     CHECK constraint is represented once, without replaying intermediate DDL.
--
-- Migration coverage: 63 schema-producing migrations are represented below.
-- The following active migration is intentionally not translated to DDL:
--   2026_08_10_210500_backfill_support_ticket_sla.php
-- It only updates existing support-ticket rows, and its data operation is
-- excluded by request. Therefore this file is a complete DDL snapshot of the
-- schema-producing migrations, but is not a data-migration replay.
--
-- The platform-settings migration also inserts one bootstrap row. That insert
-- is intentionally omitted because this artifact must contain DDL only.
--
-- DDL source migrations:
--   0001_01_01_000000_create_users_table.php
--   0001_01_01_000001_create_cache_table.php
--   0001_01_01_000002_create_jobs_table.php
--   2026_07_11_214457_create_personal_access_tokens_table.php
--   2026_07_11_214818_create_authentication_events_table.php
--   2026_07_11_214818_create_authentication_sessions_table.php
--   2026_07_11_214818_create_otp_challenges_table.php
--   2026_07_11_214818_create_patient_profiles_table.php
--   2026_07_14_203127_add_context_to_otp_challenges_table.php
--   2026_07_14_203129_make_user_password_nullable.php
--   2026_07_14_203348_add_actor_context_to_authentication_sessions_table.php
--   2026_07_15_030814_create_roles_table.php
--   2026_07_15_030815_create_role_user_table.php
--   2026_07_20_164003_create_clinics_table.php
--   2026_07_20_164004_create_plans_table.php
--   2026_07_20_164005_create_clinic_memberships_table.php
--   2026_07_20_164006_create_clinic_subscriptions_table.php
--   2026_07_20_164007_create_subscription_payments_table.php
--   2026_07_21_181633_create_doctor_profiles_table.php
--   2026_07_21_181635_create_doctor_subscriptions_table.php
--   2026_07_22_120000_add_resend_tracking_to_otp_challenges.php
--   2026_07_22_120100_add_clinic_id_to_patient_profiles.php
--   2026_07_22_120200_add_registration_lookup_indexes.php
--   2026_07_30_153206_add_delivery_status_to_otp_challenges_table.php
--   2026_07_30_153909_add_enum_check_constraints.php
--   2026_07_30_153955_add_admin_clinic_dashboard_indexes.php
--   2026_08_07_100000_add_description_to_plans_table.php
--   2026_08_07_100001_add_auto_renew_to_clinic_subscriptions_table.php
--   2026_08_10_180000_create_system_incidents_table.php
--   2026_08_10_180100_create_admin_notification_reads_table.php
--   2026_08_10_180200_add_manual_reconciliation_fields_to_subscription_payments_table.php
--   2026_08_10_185625_create_support_tickets_table.php
--   2026_08_10_193410_create_platform_settings_table.php
--   2026_08_10_210000_add_workflow_to_support_tickets.php
--   2026_08_10_211000_create_admin_audit_events_table.php
--   2026_08_10_220000_add_emergency_policy_to_platform_settings.php
--   2026_08_10_220100_add_product_configuration_to_platform_settings_table.php
--   2026_08_14_211314_add_receptionist_to_roles_key_check.php
--   2026_08_17_180357_create_appointments_table.php
--   2026_08_17_184500_add_receptionist_to_auth_context_checks.php
--   2026_08_25_120000_create_specialties_table.php
--   2026_08_25_120100_add_specialty_relations_to_doctor_profiles_table.php
--   2026_08_25_120200_add_demographics_to_patient_profiles_table.php
--   2026_08_25_120300_create_patient_intakes_table.php
--   2026_08_25_120400_add_triage_fields_to_patient_intakes_table.php
--   2026_08_25_130000_create_clinical_history_sections_table.php
--   2026_08_25_130100_create_clinical_history_records_table.php
--   2026_08_25_130200_create_clinical_history_entries_table.php
--   2026_08_25_130300_create_consultations_table.php
--   2026_08_31_120100_add_registration_metadata_to_patient_profiles_table.php
--   2026_08_31_120200_add_preferences_to_clinics_table.php
--   2026_08_31_140000_add_independent_doctor_ownership.php
--   2026_08_31_150000_add_preferences_to_doctor_profiles_table.php
--   2026_09_02_120000_add_mobile_metadata_to_personal_access_tokens_table.php
--   2026_09_02_120100_create_receptionist_invitations_table.php
--   2026_09_07_120000_add_emergency_publication_to_platform_settings.php
--   2026_09_08_150424_create_clinical_notifications_table.php
--   2026_09_09_120000_create_clinic_invoices_table.php
--   2026_09_09_120001_create_clinic_invoice_items_table.php
--   2026_09_09_120002_create_clinic_invoice_payments_table.php
--   2026_09_09_120003_add_clinic_billing_check_constraints.php
--   2026_09_09_130000_create_doctor_workspace_tables.php
--   2026_09_09_140000_create_clinic_receptionist_settings_table.php

BEGIN;

-- ---------------------------------------------------------------------------
-- Foundation and Laravel framework tables
-- ---------------------------------------------------------------------------

CREATE TABLE "users" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "name" varchar(255) NOT NULL,
    "email" varchar(255) NULL,
    "email_verified_at" timestamp(0) without time zone NULL,
    "phone_number" varchar(16) NOT NULL,
    "country_code" varchar(2) NOT NULL,
    "phone_verified_at" timestamp(0) without time zone NULL,
    "password" varchar(255) NULL,
    "status" varchar(255) NOT NULL DEFAULT 'pending_verification',
    "onboarding_status" varchar(255) NOT NULL DEFAULT 'phone_verification',
    "last_login_at" timestamp(0) without time zone NULL,
    "remember_token" varchar(100) NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "users_email_unique" UNIQUE ("email"),
    CONSTRAINT "users_phone_number_unique" UNIQUE ("phone_number")
);

CREATE INDEX "users_status_index" ON "users" ("status");
CREATE INDEX "users_onboarding_status_index" ON "users" ("onboarding_status");

CREATE TABLE "password_reset_tokens" (
    "email" varchar(255) NOT NULL PRIMARY KEY,
    "token" varchar(255) NOT NULL,
    "created_at" timestamp(0) without time zone NULL
);

CREATE TABLE "sessions" (
    "id" varchar(255) NOT NULL PRIMARY KEY,
    "user_id" bigint NULL,
    "ip_address" varchar(45) NULL,
    "user_agent" text NULL,
    "payload" text NOT NULL,
    "last_activity" integer NOT NULL
);

CREATE INDEX "sessions_user_id_index" ON "sessions" ("user_id");
CREATE INDEX "sessions_last_activity_index" ON "sessions" ("last_activity");

CREATE TABLE "cache" (
    "key" varchar(255) NOT NULL PRIMARY KEY,
    "value" text NOT NULL,
    "expiration" bigint NOT NULL
);

CREATE INDEX "cache_expiration_index" ON "cache" ("expiration");

CREATE TABLE "cache_locks" (
    "key" varchar(255) NOT NULL PRIMARY KEY,
    "owner" varchar(255) NOT NULL,
    "expiration" bigint NOT NULL
);

CREATE INDEX "cache_locks_expiration_index" ON "cache_locks" ("expiration");

CREATE TABLE "jobs" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "queue" varchar(255) NOT NULL,
    "payload" text NOT NULL,
    "attempts" smallint NOT NULL,
    "reserved_at" integer NULL,
    "available_at" integer NOT NULL,
    "created_at" integer NOT NULL
);

CREATE INDEX "jobs_queue_index" ON "jobs" ("queue");

CREATE TABLE "job_batches" (
    "id" varchar(255) NOT NULL PRIMARY KEY,
    "name" varchar(255) NOT NULL,
    "total_jobs" integer NOT NULL,
    "pending_jobs" integer NOT NULL,
    "failed_jobs" integer NOT NULL,
    "failed_job_ids" text NOT NULL,
    "options" text NULL,
    "cancelled_at" integer NULL,
    "created_at" integer NOT NULL,
    "finished_at" integer NULL
);

CREATE TABLE "failed_jobs" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "uuid" varchar(255) NOT NULL,
    "connection" varchar(255) NOT NULL,
    "queue" varchar(255) NOT NULL,
    "payload" text NOT NULL,
    "exception" text NOT NULL,
    "failed_at" timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "failed_jobs_uuid_unique" UNIQUE ("uuid")
);

CREATE INDEX "failed_jobs_connection_queue_failed_at_index"
    ON "failed_jobs" ("connection", "queue", "failed_at");

-- ---------------------------------------------------------------------------
-- Authentication and access tables
-- ---------------------------------------------------------------------------

CREATE TABLE "roles" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "key" varchar(255) NOT NULL,
    "name" varchar(255) NOT NULL,
    "scope" varchar(255) NOT NULL,
    "is_system" boolean NOT NULL DEFAULT false,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "roles_key_unique" UNIQUE ("key")
);

CREATE INDEX "roles_scope_index" ON "roles" ("scope");

CREATE TABLE "personal_access_tokens" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "tokenable_type" varchar(255) NOT NULL,
    "tokenable_id" bigint NOT NULL,
    "name" text NOT NULL,
    "token" varchar(64) NOT NULL,
    "abilities" text NULL,
    "client_type" varchar(255) NOT NULL DEFAULT 'web',
    "actor_context" varchar(255) NULL,
    "device_name" varchar(100) NULL,
    "device_id_hash" varchar(64) NULL,
    "last_used_at" timestamp(0) without time zone NULL,
    "expires_at" timestamp(0) without time zone NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "personal_access_tokens_token_unique" UNIQUE ("token")
);

CREATE INDEX "personal_access_tokens_tokenable_type_tokenable_id_index"
    ON "personal_access_tokens" ("tokenable_type", "tokenable_id");
CREATE INDEX "personal_access_tokens_expires_at_index"
    ON "personal_access_tokens" ("expires_at");
CREATE INDEX "personal_access_tokens_mobile_device_index"
    ON "personal_access_tokens" ("tokenable_id", "client_type", "device_id_hash");

CREATE TABLE "authentication_events" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "user_id" bigint NULL,
    "event" varchar(255) NOT NULL,
    "outcome" varchar(255) NOT NULL,
    "ip_address" varchar(45) NULL,
    "user_agent" text NULL,
    "request_id" varchar(255) NULL,
    "metadata" jsonb NOT NULL DEFAULT '{}',
    "occurred_at" timestamp(0) without time zone NOT NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "authentication_events_user_id_foreign"
        FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE SET NULL
);

CREATE INDEX "authentication_events_event_index" ON "authentication_events" ("event");
CREATE INDEX "authentication_events_outcome_index" ON "authentication_events" ("outcome");
CREATE INDEX "authentication_events_request_id_index" ON "authentication_events" ("request_id");
CREATE INDEX "authentication_events_occurred_at_index" ON "authentication_events" ("occurred_at");
CREATE INDEX "authentication_events_user_id_occurred_at_index"
    ON "authentication_events" ("user_id", "occurred_at");

CREATE TABLE "authentication_sessions" (
    "id" uuid NOT NULL PRIMARY KEY,
    "user_id" bigint NOT NULL,
    "session_id_hash" varchar(64) NOT NULL,
    "actor_context" varchar(255) NOT NULL DEFAULT 'staff',
    "ip_address" varchar(45) NULL,
    "user_agent" text NULL,
    "last_seen_at" timestamp(0) without time zone NOT NULL,
    "expires_at" timestamp(0) without time zone NOT NULL,
    "revoked_at" timestamp(0) without time zone NULL,
    "revoked_reason" varchar(255) NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "authentication_sessions_user_id_foreign"
        FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "authentication_sessions_session_id_hash_unique"
        UNIQUE ("session_id_hash")
);

CREATE INDEX "authentication_sessions_expires_at_index"
    ON "authentication_sessions" ("expires_at");
CREATE INDEX "authentication_sessions_revoked_at_index"
    ON "authentication_sessions" ("revoked_at");

CREATE TABLE "otp_challenges" (
    "id" uuid NOT NULL PRIMARY KEY,
    "flow_id" uuid NULL,
    "user_id" bigint NOT NULL,
    "purpose" varchar(255) NOT NULL,
    "client_type" varchar(255) NOT NULL DEFAULT 'web',
    "actor_context" varchar(255) NOT NULL DEFAULT 'patient',
    "device_name" varchar(255) NULL,
    "device_id_hash" varchar(64) NULL,
    "code_hash" varchar(64) NULL,
    "attempts" smallint NOT NULL DEFAULT 0,
    "max_attempts" smallint NOT NULL DEFAULT 5,
    "resend_count" smallint NOT NULL DEFAULT 0,
    "delivery_status" varchar(255) NOT NULL DEFAULT 'sent',
    "delivery_sequence" integer NOT NULL DEFAULT 1,
    "sent_at" timestamp(0) without time zone NOT NULL,
    "expires_at" timestamp(0) without time zone NOT NULL,
    "consumed_at" timestamp(0) without time zone NULL,
    "invalidated_at" timestamp(0) without time zone NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "otp_challenges_user_id_foreign"
        FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE
);

CREATE INDEX "otp_challenges_flow_id_index" ON "otp_challenges" ("flow_id");
CREATE INDEX "otp_challenges_user_id_purpose_expires_at_index"
    ON "otp_challenges" ("user_id", "purpose", "expires_at");
CREATE INDEX "otp_challenges_user_id_client_type_actor_context_index"
    ON "otp_challenges" ("user_id", "client_type", "actor_context");
CREATE INDEX "otp_challenges_delivery_sequence_index"
    ON "otp_challenges" ("user_id", "purpose", "delivery_sequence");

-- ---------------------------------------------------------------------------
-- Clinics, plans, specialties, and role membership
-- ---------------------------------------------------------------------------

CREATE TABLE "clinics" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "name" varchar(160) NOT NULL,
    "slug" varchar(180) NOT NULL,
    "tax_id" varchar(80) NOT NULL,
    "country_code" varchar(2) NOT NULL,
    "contact_phone" varchar(16) NOT NULL,
    "address" varchar(240) NOT NULL,
    "status" varchar(255) NOT NULL DEFAULT 'active',
    "preferences" json NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "clinics_slug_unique" UNIQUE ("slug"),
    CONSTRAINT "clinics_tax_id_unique" UNIQUE ("tax_id")
);

CREATE INDEX "clinics_country_code_index" ON "clinics" ("country_code");
CREATE INDEX "clinics_status_index" ON "clinics" ("status");
CREATE INDEX "clinics_registration_lookup_index" ON "clinics" ("status", "name");

CREATE TABLE "patient_profiles" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "user_id" bigint NOT NULL,
    "clinic_id" bigint NULL,
    "owner_doctor_user_id" bigint NULL,
    "document_type" varchar(40) NULL,
    "document_number" varchar(80) NULL,
    "date_of_birth" date NULL,
    "sex" varchar(40) NULL,
    "address" varchar(240) NULL,
    "emergency_contact" varchar(160) NULL,
    "insurance" varchar(120) NULL,
    "is_returning_patient" boolean NOT NULL DEFAULT false,
    "whatsapp_consent" boolean NOT NULL DEFAULT false,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "patient_profiles_user_id_foreign"
        FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "patient_profiles_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE SET NULL,
    CONSTRAINT "patient_profiles_owner_doctor_user_id_foreign"
        FOREIGN KEY ("owner_doctor_user_id") REFERENCES "users" ("id") ON DELETE SET NULL,
    CONSTRAINT "patient_profiles_user_id_unique" UNIQUE ("user_id")
);

CREATE INDEX "patient_profiles_clinic_id_document_type_document_number_index"
    ON "patient_profiles" ("clinic_id", "document_type", "document_number");
CREATE INDEX "patient_profiles_owner_doctor_user_id_id_index"
    ON "patient_profiles" ("owner_doctor_user_id", "id");
CREATE INDEX "patient_profiles_clinic_id_idx"
    ON "patient_profiles" ("clinic_id")
    WHERE "clinic_id" IS NOT NULL;

CREATE TABLE "plans" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "key" varchar(255) NOT NULL,
    "name" varchar(255) NOT NULL,
    "description" text NULL,
    "monthly_price_cents" integer NOT NULL,
    "annual_price_cents" integer NOT NULL,
    "currency" varchar(3) NOT NULL DEFAULT 'USD',
    "features" jsonb NOT NULL,
    "publicly_selectable" boolean NOT NULL DEFAULT true,
    "is_active" boolean NOT NULL DEFAULT true,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "plans_key_unique" UNIQUE ("key")
);

CREATE INDEX "plans_publicly_selectable_index" ON "plans" ("publicly_selectable");
CREATE INDEX "plans_is_active_index" ON "plans" ("is_active");

CREATE TABLE "specialties" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "code" varchar(80) NOT NULL,
    "name" varchar(160) NOT NULL,
    "parent_id" bigint NULL,
    "is_active" boolean NOT NULL DEFAULT true,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "specialties_code_unique" UNIQUE ("code"),
    CONSTRAINT "specialties_parent_id_foreign"
        FOREIGN KEY ("parent_id") REFERENCES "specialties" ("id")
        ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE INDEX "specialties_is_active_index" ON "specialties" ("is_active");

CREATE TABLE "doctor_profiles" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "user_id" bigint NOT NULL,
    "clinic_id" bigint NULL,
    "medical_license" varchar(80) NOT NULL,
    "specialty" varchar(120) NOT NULL,
    "specialty_id" bigint NULL,
    "subspecialty_id" bigint NULL,
    "practice_type" varchar(255) NOT NULL,
    "preferences" json NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "doctor_profiles_user_id_foreign"
        FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "doctor_profiles_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE SET NULL,
    CONSTRAINT "doctor_profiles_specialty_id_foreign"
        FOREIGN KEY ("specialty_id") REFERENCES "specialties" ("id") ON DELETE SET NULL,
    CONSTRAINT "doctor_profiles_subspecialty_id_foreign"
        FOREIGN KEY ("subspecialty_id") REFERENCES "specialties" ("id") ON DELETE SET NULL,
    CONSTRAINT "doctor_profiles_user_id_unique" UNIQUE ("user_id"),
    CONSTRAINT "doctor_profiles_medical_license_unique" UNIQUE ("medical_license")
);

CREATE INDEX "doctor_profiles_practice_type_index" ON "doctor_profiles" ("practice_type");

CREATE TABLE "role_user" (
    "user_id" bigint NOT NULL,
    "role_id" bigint NOT NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "role_user_user_id_foreign"
        FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "role_user_role_id_foreign"
        FOREIGN KEY ("role_id") REFERENCES "roles" ("id") ON DELETE CASCADE,
    CONSTRAINT "role_user_pkey" PRIMARY KEY ("user_id", "role_id")
);

CREATE TABLE "clinic_memberships" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NOT NULL,
    "user_id" bigint NOT NULL,
    "role_id" bigint NOT NULL,
    "status" varchar(255) NOT NULL DEFAULT 'active',
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "clinic_memberships_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinic_memberships_user_id_foreign"
        FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinic_memberships_role_id_foreign"
        FOREIGN KEY ("role_id") REFERENCES "roles" ("id") ON DELETE RESTRICT,
    CONSTRAINT "clinic_memberships_clinic_id_user_id_unique"
        UNIQUE ("clinic_id", "user_id")
);

CREATE INDEX "clinic_memberships_status_index" ON "clinic_memberships" ("status");
CREATE INDEX "clinic_memberships_user_id_status_index"
    ON "clinic_memberships" ("user_id", "status");

-- ---------------------------------------------------------------------------
-- Billing and subscriptions
-- ---------------------------------------------------------------------------

CREATE TABLE "clinic_subscriptions" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NOT NULL,
    "plan_id" bigint NOT NULL,
    "billing_cycle" varchar(255) NOT NULL,
    "status" varchar(255) NOT NULL DEFAULT 'active',
    "auto_renew" boolean NOT NULL DEFAULT true,
    "starts_at" timestamp(0) without time zone NOT NULL,
    "ends_at" timestamp(0) without time zone NULL,
    "provider" varchar(255) NOT NULL DEFAULT 'simulated',
    "provider_reference" varchar(255) NOT NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "clinic_subscriptions_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinic_subscriptions_plan_id_foreign"
        FOREIGN KEY ("plan_id") REFERENCES "plans" ("id") ON DELETE RESTRICT,
    CONSTRAINT "clinic_subscriptions_provider_reference_unique"
        UNIQUE ("provider_reference")
);

CREATE INDEX "clinic_subscriptions_billing_cycle_index"
    ON "clinic_subscriptions" ("billing_cycle");
CREATE INDEX "clinic_subscriptions_status_index" ON "clinic_subscriptions" ("status");
CREATE INDEX "clinic_subscriptions_clinic_id_status_index"
    ON "clinic_subscriptions" ("clinic_id", "status");
CREATE INDEX "clinic_subscriptions_access_lookup_index"
    ON "clinic_subscriptions" ("clinic_id", "status", "ends_at");
CREATE INDEX "clinic_subscriptions_clinic_starts_id_idx"
    ON "clinic_subscriptions" ("clinic_id", "starts_at" DESC, "id" DESC);

CREATE TABLE "doctor_subscriptions" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "doctor_profile_id" bigint NOT NULL,
    "plan_id" bigint NOT NULL,
    "billing_cycle" varchar(255) NOT NULL,
    "status" varchar(255) NOT NULL DEFAULT 'active',
    "starts_at" timestamp(0) without time zone NOT NULL,
    "ends_at" timestamp(0) without time zone NULL,
    "provider" varchar(255) NOT NULL DEFAULT 'simulated',
    "provider_reference" varchar(255) NOT NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "doctor_subscriptions_doctor_profile_id_foreign"
        FOREIGN KEY ("doctor_profile_id") REFERENCES "doctor_profiles" ("id") ON DELETE CASCADE,
    CONSTRAINT "doctor_subscriptions_plan_id_foreign"
        FOREIGN KEY ("plan_id") REFERENCES "plans" ("id") ON DELETE RESTRICT,
    CONSTRAINT "doctor_subscriptions_provider_reference_unique"
        UNIQUE ("provider_reference")
);

CREATE INDEX "doctor_subscriptions_billing_cycle_index"
    ON "doctor_subscriptions" ("billing_cycle");
CREATE INDEX "doctor_subscriptions_status_index" ON "doctor_subscriptions" ("status");
CREATE INDEX "doctor_subscriptions_doctor_profile_id_status_index"
    ON "doctor_subscriptions" ("doctor_profile_id", "status");

CREATE TABLE "subscription_payments" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_subscription_id" bigint NOT NULL,
    "idempotency_key" varchar(255) NOT NULL,
    "amount_cents" integer NOT NULL,
    "currency" varchar(3) NOT NULL DEFAULT 'USD',
    "status" varchar(255) NOT NULL DEFAULT 'succeeded',
    "provider" varchar(255) NOT NULL DEFAULT 'simulated',
    "provider_reference" varchar(255) NOT NULL,
    "reconciliation_note" text NULL,
    "refund_reference" varchar(255) NULL,
    "paid_at" timestamp(0) without time zone NULL,
    "refunded_at" timestamp(0) without time zone NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "subscription_payments_clinic_subscription_id_foreign"
        FOREIGN KEY ("clinic_subscription_id") REFERENCES "clinic_subscriptions" ("id") ON DELETE CASCADE,
    CONSTRAINT "subscription_payments_idempotency_key_unique"
        UNIQUE ("idempotency_key"),
    CONSTRAINT "subscription_payments_provider_reference_unique"
        UNIQUE ("provider_reference"),
    CONSTRAINT "subscription_payments_refund_reference_unique"
        UNIQUE ("refund_reference")
);

CREATE INDEX "subscription_payments_status_index" ON "subscription_payments" ("status");
CREATE INDEX "subscription_payments_subscription_paid_id_idx"
    ON "subscription_payments" ("clinic_subscription_id", "paid_at" DESC, "id" DESC)
    WHERE "status" = 'succeeded' AND "paid_at" IS NOT NULL;

-- ---------------------------------------------------------------------------
-- Clinical scheduling and patient records
-- ---------------------------------------------------------------------------

CREATE TABLE "appointments" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NULL,
    "patient_profile_id" bigint NOT NULL,
    "doctor_user_id" bigint NULL,
    "scheduled_at" timestamp(0) without time zone NOT NULL,
    "status" varchar(255) NOT NULL DEFAULT 'pending',
    "appointment_type" varchar(255) NOT NULL DEFAULT 'consultation',
    "notes" text NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "appointments_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE CASCADE,
    CONSTRAINT "appointments_patient_profile_id_foreign"
        FOREIGN KEY ("patient_profile_id") REFERENCES "patient_profiles" ("id") ON DELETE CASCADE,
    CONSTRAINT "appointments_doctor_user_id_foreign"
        FOREIGN KEY ("doctor_user_id") REFERENCES "users" ("id") ON DELETE SET NULL
);

CREATE INDEX "appointments_clinic_id_scheduled_at_status_index"
    ON "appointments" ("clinic_id", "scheduled_at", "status");

CREATE TABLE "patient_intakes" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NOT NULL,
    "patient_profile_id" bigint NOT NULL,
    "registered_by_user_id" bigint NOT NULL,
    "requested_doctor_user_id" bigint NULL,
    "specialty_id" bigint NULL,
    "subspecialty_id" bigint NULL,
    "reason_category" varchar(80) NULL,
    "objective" text NOT NULL,
    "observations" text NULL,
    "temperature_c" decimal(4, 1) NULL,
    "systolic_bp" smallint NULL,
    "diastolic_bp" smallint NULL,
    "heart_rate" smallint NULL,
    "respiratory_rate" smallint NULL,
    "oxygen_saturation" smallint NULL,
    "triage_priority" varchar(20) NULL,
    "triage_notes" text NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "patient_intakes_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE CASCADE,
    CONSTRAINT "patient_intakes_patient_profile_id_foreign"
        FOREIGN KEY ("patient_profile_id") REFERENCES "patient_profiles" ("id") ON DELETE CASCADE,
    CONSTRAINT "patient_intakes_registered_by_user_id_foreign"
        FOREIGN KEY ("registered_by_user_id") REFERENCES "users" ("id") ON DELETE RESTRICT,
    CONSTRAINT "patient_intakes_requested_doctor_user_id_foreign"
        FOREIGN KEY ("requested_doctor_user_id") REFERENCES "users" ("id") ON DELETE SET NULL,
    CONSTRAINT "patient_intakes_specialty_id_foreign"
        FOREIGN KEY ("specialty_id") REFERENCES "specialties" ("id") ON DELETE SET NULL,
    CONSTRAINT "patient_intakes_subspecialty_id_foreign"
        FOREIGN KEY ("subspecialty_id") REFERENCES "specialties" ("id") ON DELETE SET NULL
);

CREATE INDEX "patient_intakes_clinic_id_patient_profile_id_index"
    ON "patient_intakes" ("clinic_id", "patient_profile_id");

CREATE TABLE "clinical_history_sections" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "key" varchar(80) NOT NULL,
    "title" varchar(160) NOT NULL,
    "description" varchar(500) NULL,
    "sort_order" integer NOT NULL DEFAULT 0,
    "content_schema" json NOT NULL,
    "is_active" boolean NOT NULL DEFAULT true,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "clinical_history_sections_key_unique" UNIQUE ("key")
);

CREATE INDEX "clinical_history_sections_sort_order_index"
    ON "clinical_history_sections" ("sort_order");
CREATE INDEX "clinical_history_sections_is_active_index"
    ON "clinical_history_sections" ("is_active");

CREATE TABLE "clinical_history_records" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NULL,
    "patient_profile_id" bigint NOT NULL,
    "doctor_user_id" bigint NOT NULL,
    "appointment_id" bigint NULL,
    "created_by_user_id" bigint NULL,
    "source" varchar(40) NOT NULL DEFAULT 'consultation',
    "objective" text NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "clinical_history_records_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinical_history_records_patient_profile_id_foreign"
        FOREIGN KEY ("patient_profile_id") REFERENCES "patient_profiles" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinical_history_records_doctor_user_id_foreign"
        FOREIGN KEY ("doctor_user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinical_history_records_appointment_id_foreign"
        FOREIGN KEY ("appointment_id") REFERENCES "appointments" ("id") ON DELETE SET NULL,
    CONSTRAINT "clinical_history_records_created_by_user_id_foreign"
        FOREIGN KEY ("created_by_user_id") REFERENCES "users" ("id") ON DELETE SET NULL
);

CREATE INDEX "clinical_history_records_source_index" ON "clinical_history_records" ("source");
CREATE INDEX "clinical_history_records_clinic_id_patient_profile_id_created_a"
    ON "clinical_history_records" ("clinic_id", "patient_profile_id", "created_at");

CREATE TABLE "clinical_history_entries" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinical_history_record_id" bigint NOT NULL,
    "clinical_history_section_id" bigint NOT NULL,
    "values" json NOT NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "clinical_history_entries_clinical_history_record_id_foreign"
        FOREIGN KEY ("clinical_history_record_id") REFERENCES "clinical_history_records" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinical_history_entries_clinical_history_section_id_foreign"
        FOREIGN KEY ("clinical_history_section_id") REFERENCES "clinical_history_sections" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinical_history_entry_record_section_unique"
        UNIQUE ("clinical_history_record_id", "clinical_history_section_id")
);

CREATE TABLE "consultations" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NULL,
    "doctor_user_id" bigint NOT NULL,
    "patient_profile_id" bigint NOT NULL,
    "appointment_id" bigint NULL,
    "clinical_history_record_id" bigint NULL,
    "created_by_user_id" bigint NULL,
    "status" varchar(30) NOT NULL DEFAULT 'in_progress',
    "chief_complaint" text NOT NULL,
    "objective" text NULL,
    "signed_by_user_id" bigint NULL,
    "signed_at" timestamp(0) without time zone NULL,
    "completed_at" timestamp(0) without time zone NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "consultations_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE CASCADE,
    CONSTRAINT "consultations_doctor_user_id_foreign"
        FOREIGN KEY ("doctor_user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "consultations_patient_profile_id_foreign"
        FOREIGN KEY ("patient_profile_id") REFERENCES "patient_profiles" ("id") ON DELETE CASCADE,
    CONSTRAINT "consultations_appointment_id_foreign"
        FOREIGN KEY ("appointment_id") REFERENCES "appointments" ("id") ON DELETE SET NULL,
    CONSTRAINT "consultations_clinical_history_record_id_foreign"
        FOREIGN KEY ("clinical_history_record_id") REFERENCES "clinical_history_records" ("id") ON DELETE SET NULL,
    CONSTRAINT "consultations_created_by_user_id_foreign"
        FOREIGN KEY ("created_by_user_id") REFERENCES "users" ("id") ON DELETE SET NULL,
    CONSTRAINT "consultations_signed_by_user_id_foreign"
        FOREIGN KEY ("signed_by_user_id") REFERENCES "users" ("id") ON DELETE SET NULL
);

CREATE INDEX "consultations_status_index" ON "consultations" ("status");
CREATE INDEX "consultations_clinic_id_patient_profile_id_index"
    ON "consultations" ("clinic_id", "patient_profile_id");
CREATE INDEX "consultations_clinic_id_doctor_user_id_index"
    ON "consultations" ("clinic_id", "doctor_user_id");

-- ---------------------------------------------------------------------------
-- Platform operations, support, notifications, and invitations
-- ---------------------------------------------------------------------------

CREATE TABLE "system_incidents" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "title" varchar(255) NOT NULL,
    "description" text NOT NULL,
    "severity" varchar(255) NOT NULL,
    "status" varchar(255) NOT NULL,
    "service_key" varchar(255) NULL,
    "country_code" varchar(2) NULL,
    "created_by_user_id" bigint NULL,
    "resolved_by_user_id" bigint NULL,
    "started_at" timestamp(0) without time zone NOT NULL,
    "resolved_at" timestamp(0) without time zone NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "system_incidents_created_by_user_id_foreign"
        FOREIGN KEY ("created_by_user_id") REFERENCES "users" ("id") ON DELETE SET NULL,
    CONSTRAINT "system_incidents_resolved_by_user_id_foreign"
        FOREIGN KEY ("resolved_by_user_id") REFERENCES "users" ("id") ON DELETE SET NULL
);

CREATE INDEX "system_incidents_severity_index" ON "system_incidents" ("severity");
CREATE INDEX "system_incidents_status_index" ON "system_incidents" ("status");
CREATE INDEX "system_incidents_service_key_index" ON "system_incidents" ("service_key");
CREATE INDEX "system_incidents_country_code_index" ON "system_incidents" ("country_code");
CREATE INDEX "system_incidents_started_at_index" ON "system_incidents" ("started_at");
CREATE INDEX "system_incidents_resolved_at_index" ON "system_incidents" ("resolved_at");

CREATE TABLE "admin_notification_reads" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "user_id" bigint NOT NULL,
    "source_type" varchar(32) NOT NULL,
    "source_id" varchar(64) NOT NULL,
    "read_at" timestamp(0) without time zone NOT NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "admin_notification_reads_user_id_foreign"
        FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "admin_notification_reads_unique"
        UNIQUE ("user_id", "source_type", "source_id")
);

CREATE INDEX "admin_notification_reads_user_id_read_at_index"
    ON "admin_notification_reads" ("user_id", "read_at");

CREATE TABLE "support_tickets" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "ticket_id" varchar(24) NULL,
    "clinic_id" bigint NOT NULL,
    "requested_by_user_id" bigint NULL,
    "assigned_to_user_id" bigint NULL,
    "category" varchar(40) NOT NULL,
    "priority" varchar(20) NOT NULL DEFAULT 'normal',
    "status" varchar(30) NOT NULL DEFAULT 'open',
    "subject" varchar(180) NOT NULL,
    "description" text NOT NULL,
    "contact_email" varchar(254) NOT NULL,
    "notify_on_create" boolean NOT NULL DEFAULT true,
    "due_at" timestamp(0) without time zone NULL,
    "first_responded_at" timestamp(0) without time zone NULL,
    "resolved_at" timestamp(0) without time zone NULL,
    "closed_at" timestamp(0) without time zone NULL,
    "last_activity_at" timestamp(0) without time zone NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "support_tickets_ticket_id_unique" UNIQUE ("ticket_id"),
    CONSTRAINT "support_tickets_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE RESTRICT,
    CONSTRAINT "support_tickets_requested_by_user_id_foreign"
        FOREIGN KEY ("requested_by_user_id") REFERENCES "users" ("id") ON DELETE SET NULL,
    CONSTRAINT "support_tickets_assigned_to_user_id_foreign"
        FOREIGN KEY ("assigned_to_user_id") REFERENCES "users" ("id") ON DELETE SET NULL
);

CREATE INDEX "support_tickets_priority_index" ON "support_tickets" ("priority");
CREATE INDEX "support_tickets_status_index" ON "support_tickets" ("status");
CREATE INDEX "support_tickets_due_at_index" ON "support_tickets" ("due_at");
CREATE INDEX "support_tickets_last_activity_at_index"
    ON "support_tickets" ("last_activity_at");
CREATE INDEX "support_tickets_clinic_id_created_at_index"
    ON "support_tickets" ("clinic_id", "created_at");
CREATE INDEX "support_tickets_requested_by_user_id_created_at_index"
    ON "support_tickets" ("requested_by_user_id", "created_at");

CREATE TABLE "support_ticket_activities" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "support_ticket_id" bigint NOT NULL,
    "actor_user_id" bigint NULL,
    "type" varchar(40) NOT NULL,
    "body" text NULL,
    "from_value" varchar(120) NULL,
    "to_value" varchar(120) NULL,
    "is_internal" boolean NOT NULL DEFAULT true,
    "metadata" json NULL,
    "created_at" timestamp(0) without time zone NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "support_ticket_activities_support_ticket_id_foreign"
        FOREIGN KEY ("support_ticket_id") REFERENCES "support_tickets" ("id") ON DELETE CASCADE,
    CONSTRAINT "support_ticket_activities_actor_user_id_foreign"
        FOREIGN KEY ("actor_user_id") REFERENCES "users" ("id") ON DELETE SET NULL
);

CREATE INDEX "support_ticket_activities_type_index" ON "support_ticket_activities" ("type");
CREATE INDEX "support_ticket_activities_support_ticket_id_created_at_index"
    ON "support_ticket_activities" ("support_ticket_id", "created_at");

CREATE TABLE "platform_settings" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "default_country_code" varchar(2) NOT NULL DEFAULT 'MX',
    "enabled_country_codes" json NOT NULL,
    "emergency_policy" json NULL,
    "emergency_responsibles" json NULL,
    "emergency_published_at" timestamp(0) without time zone NULL,
    "emergency_publication" json NULL,
    "platform_name" varchar(80) NOT NULL DEFAULT 'MEDIQ',
    "support_email" varchar(255) NULL,
    "support_url" varchar(255) NULL,
    "default_locale" varchar(10) NOT NULL DEFAULT 'es-MX',
    "enabled_locales" json NULL,
    "ai_configuration" json NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL
);

CREATE TABLE "admin_audit_events" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "actor_user_id" bigint NULL,
    "clinic_id" bigint NULL,
    "country_code" char(2) NULL,
    "module" varchar(60) NOT NULL,
    "action" varchar(80) NOT NULL,
    "is_critical" boolean NOT NULL DEFAULT false,
    "resource_type" varchar(120) NULL,
    "resource_id" varchar(80) NULL,
    "resource_label" varchar(180) NULL,
    "ip_address" varchar(45) NULL,
    "user_agent" text NULL,
    "request_id" varchar(255) NULL,
    "metadata" json NULL,
    "occurred_at" timestamp(0) without time zone NOT NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "admin_audit_events_actor_user_id_foreign"
        FOREIGN KEY ("actor_user_id") REFERENCES "users" ("id") ON DELETE SET NULL,
    CONSTRAINT "admin_audit_events_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE SET NULL
);

CREATE INDEX "admin_audit_events_country_code_index"
    ON "admin_audit_events" ("country_code");
CREATE INDEX "admin_audit_events_module_index" ON "admin_audit_events" ("module");
CREATE INDEX "admin_audit_events_action_index" ON "admin_audit_events" ("action");
CREATE INDEX "admin_audit_events_is_critical_index"
    ON "admin_audit_events" ("is_critical");
CREATE INDEX "admin_audit_events_request_id_index"
    ON "admin_audit_events" ("request_id");
CREATE INDEX "admin_audit_events_occurred_at_index"
    ON "admin_audit_events" ("occurred_at");
CREATE INDEX "admin_audit_events_module_occurred_at_index"
    ON "admin_audit_events" ("module", "occurred_at");
CREATE INDEX "admin_audit_events_actor_user_id_occurred_at_index"
    ON "admin_audit_events" ("actor_user_id", "occurred_at");
CREATE INDEX "admin_audit_events_clinic_id_occurred_at_index"
    ON "admin_audit_events" ("clinic_id", "occurred_at");

CREATE TABLE "receptionist_invitations" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NOT NULL,
    "invited_by_user_id" bigint NOT NULL,
    "user_id" bigint NULL,
    "name" varchar(160) NOT NULL,
    "email" varchar(254) NULL,
    "phone_number" varchar(16) NOT NULL,
    "country_code" varchar(2) NOT NULL,
    "token_hash" varchar(64) NOT NULL,
    "expires_at" timestamp(0) without time zone NOT NULL,
    "accepted_at" timestamp(0) without time zone NULL,
    "revoked_at" timestamp(0) without time zone NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "receptionist_invitations_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE CASCADE,
    CONSTRAINT "receptionist_invitations_invited_by_user_id_foreign"
        FOREIGN KEY ("invited_by_user_id") REFERENCES "users" ("id") ON DELETE RESTRICT,
    CONSTRAINT "receptionist_invitations_user_id_foreign"
        FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE SET NULL,
    CONSTRAINT "receptionist_invitations_token_hash_unique" UNIQUE ("token_hash")
);

CREATE INDEX "receptionist_invitations_expires_at_index"
    ON "receptionist_invitations" ("expires_at");
CREATE INDEX "receptionist_invitations_clinic_id_phone_number_accepted_at_rev"
    ON "receptionist_invitations" ("clinic_id", "phone_number", "accepted_at", "revoked_at");

CREATE TABLE "clinical_notifications" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NOT NULL,
    "user_id" bigint NOT NULL,
    "type" varchar(32) NOT NULL,
    "event_key" varchar(64) NOT NULL,
    "source_type" varchar(64) NOT NULL,
    "source_id" varchar(128) NOT NULL,
    "title_key" varchar(160) NOT NULL,
    "message_key" varchar(160) NOT NULL,
    "severity" varchar(16) NOT NULL,
    "href" varchar(255) NOT NULL,
    "payload" json NULL,
    "occurred_at" timestamp(0) without time zone NOT NULL,
    "read_at" timestamp(0) without time zone NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "clinical_notifications_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinical_notifications_user_id_foreign"
        FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinical_notifications_recipient_source_unique"
        UNIQUE ("user_id", "clinic_id", "source_type", "source_id")
);

CREATE INDEX "clinical_notifications_inbox_index"
    ON "clinical_notifications" ("user_id", "clinic_id", "read_at", "occurred_at");
CREATE INDEX "clinical_notifications_clinic_index"
    ON "clinical_notifications" ("clinic_id", "occurred_at");

-- ---------------------------------------------------------------------------
-- Clinic billing and doctor workspace extensions
-- ---------------------------------------------------------------------------

CREATE TABLE "clinic_invoices" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NOT NULL,
    "patient_profile_id" bigint NOT NULL,
    "invoice_number" varchar(64) NOT NULL,
    "issue_date" date NOT NULL,
    "due_date" date NULL,
    "total_minor" bigint NOT NULL,
    "currency" varchar(3) NOT NULL,
    "status" varchar(32) NOT NULL DEFAULT 'issued',
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "clinic_invoices_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinic_invoices_patient_profile_id_foreign"
        FOREIGN KEY ("patient_profile_id") REFERENCES "patient_profiles" ("id") ON DELETE RESTRICT,
    CONSTRAINT "clinic_invoice_number_unique"
        UNIQUE ("clinic_id", "invoice_number")
);

CREATE INDEX "clinic_invoices_status_index" ON "clinic_invoices" ("status");
CREATE INDEX "clinic_invoice_issue_date_index"
    ON "clinic_invoices" ("clinic_id", "issue_date");
CREATE INDEX "clinic_invoice_status_date_index"
    ON "clinic_invoices" ("clinic_id", "status", "issue_date");
CREATE INDEX "clinic_invoice_patient_date_index"
    ON "clinic_invoices" ("clinic_id", "patient_profile_id", "issue_date");

CREATE TABLE "clinic_invoice_items" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_invoice_id" bigint NOT NULL,
    "description" varchar(200) NOT NULL,
    "quantity" integer NOT NULL,
    "unit_amount_minor" bigint NOT NULL,
    "line_total_minor" bigint NOT NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "clinic_invoice_items_clinic_invoice_id_foreign"
        FOREIGN KEY ("clinic_invoice_id") REFERENCES "clinic_invoices" ("id") ON DELETE CASCADE
);

CREATE INDEX "clinic_invoice_item_invoice_index"
    ON "clinic_invoice_items" ("clinic_invoice_id");

CREATE TABLE "clinic_invoice_payments" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_invoice_id" bigint NOT NULL,
    "amount_minor" bigint NOT NULL,
    "currency" varchar(3) NOT NULL,
    "status" varchar(32) NOT NULL DEFAULT 'confirmed',
    "payment_method" varchar(32) NOT NULL,
    "reference" varchar(120) NULL,
    "note" text NULL,
    "paid_at" timestamp(0) without time zone NOT NULL,
    "recorded_by_user_id" bigint NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "clinic_invoice_payments_clinic_invoice_id_foreign"
        FOREIGN KEY ("clinic_invoice_id") REFERENCES "clinic_invoices" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinic_invoice_payments_recorded_by_user_id_foreign"
        FOREIGN KEY ("recorded_by_user_id") REFERENCES "users" ("id") ON DELETE SET NULL,
    CONSTRAINT "clinic_invoice_payments_reference_unique" UNIQUE ("reference")
);

CREATE INDEX "clinic_invoice_payments_status_index"
    ON "clinic_invoice_payments" ("status");
CREATE INDEX "clinic_invoice_payment_status_index"
    ON "clinic_invoice_payments" ("clinic_invoice_id", "status", "paid_at");
CREATE INDEX "clinic_invoice_payment_currency_index"
    ON "clinic_invoice_payments" ("currency", "paid_at");

CREATE TABLE "doctor_prescriptions" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NULL,
    "doctor_user_id" bigint NOT NULL,
    "patient_profile_id" bigint NOT NULL,
    "consultation_id" bigint NULL,
    "medication" varchar(180) NOT NULL,
    "dosage" varchar(120) NULL,
    "frequency" varchar(120) NULL,
    "duration_days" smallint NULL,
    "status" varchar(30) NOT NULL DEFAULT 'active',
    "issued_at" timestamp(0) without time zone NULL,
    "expires_at" timestamp(0) without time zone NULL,
    "notes" text NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "doctor_prescriptions_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE SET NULL,
    CONSTRAINT "doctor_prescriptions_doctor_user_id_foreign"
        FOREIGN KEY ("doctor_user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "doctor_prescriptions_patient_profile_id_foreign"
        FOREIGN KEY ("patient_profile_id") REFERENCES "patient_profiles" ("id") ON DELETE CASCADE,
    CONSTRAINT "doctor_prescriptions_consultation_id_foreign"
        FOREIGN KEY ("consultation_id") REFERENCES "consultations" ("id") ON DELETE SET NULL
);

CREATE INDEX "doctor_prescriptions_clinic_id_doctor_user_id_status_index"
    ON "doctor_prescriptions" ("clinic_id", "doctor_user_id", "status");
CREATE INDEX "doctor_prescriptions_doctor_user_id_patient_profile_id_index"
    ON "doctor_prescriptions" ("doctor_user_id", "patient_profile_id");

CREATE TABLE "laboratory_orders" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NULL,
    "doctor_user_id" bigint NOT NULL,
    "patient_profile_id" bigint NOT NULL,
    "consultation_id" bigint NULL,
    "study_name" varchar(180) NOT NULL,
    "status" varchar(30) NOT NULL DEFAULT 'requested',
    "priority" varchar(20) NOT NULL DEFAULT 'normal',
    "is_critical" boolean NOT NULL DEFAULT false,
    "ordered_at" timestamp(0) without time zone NULL,
    "result_at" timestamp(0) without time zone NULL,
    "result_summary" text NULL,
    "notes" text NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "laboratory_orders_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE SET NULL,
    CONSTRAINT "laboratory_orders_doctor_user_id_foreign"
        FOREIGN KEY ("doctor_user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "laboratory_orders_patient_profile_id_foreign"
        FOREIGN KEY ("patient_profile_id") REFERENCES "patient_profiles" ("id") ON DELETE CASCADE,
    CONSTRAINT "laboratory_orders_consultation_id_foreign"
        FOREIGN KEY ("consultation_id") REFERENCES "consultations" ("id") ON DELETE SET NULL
);

CREATE INDEX "laboratory_orders_clinic_id_doctor_user_id_status_index"
    ON "laboratory_orders" ("clinic_id", "doctor_user_id", "status");
CREATE INDEX "laboratory_orders_doctor_user_id_patient_profile_id_index"
    ON "laboratory_orders" ("doctor_user_id", "patient_profile_id");

CREATE TABLE "teleconsultations" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NULL,
    "doctor_user_id" bigint NOT NULL,
    "patient_profile_id" bigint NOT NULL,
    "appointment_id" bigint NULL,
    "consultation_id" bigint NULL,
    "status" varchar(30) NOT NULL DEFAULT 'scheduled',
    "scheduled_at" timestamp(0) without time zone NOT NULL,
    "started_at" timestamp(0) without time zone NULL,
    "ended_at" timestamp(0) without time zone NULL,
    "notes" text NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "teleconsultations_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE SET NULL,
    CONSTRAINT "teleconsultations_doctor_user_id_foreign"
        FOREIGN KEY ("doctor_user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "teleconsultations_patient_profile_id_foreign"
        FOREIGN KEY ("patient_profile_id") REFERENCES "patient_profiles" ("id") ON DELETE CASCADE,
    CONSTRAINT "teleconsultations_appointment_id_foreign"
        FOREIGN KEY ("appointment_id") REFERENCES "appointments" ("id") ON DELETE SET NULL,
    CONSTRAINT "teleconsultations_consultation_id_foreign"
        FOREIGN KEY ("consultation_id") REFERENCES "consultations" ("id") ON DELETE SET NULL
);

CREATE INDEX "teleconsultations_clinic_id_doctor_user_id_status_index"
    ON "teleconsultations" ("clinic_id", "doctor_user_id", "status");
CREATE INDEX "teleconsultations_doctor_user_id_scheduled_at_index"
    ON "teleconsultations" ("doctor_user_id", "scheduled_at");

CREATE TABLE "doctor_messages" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NULL,
    "sender_user_id" bigint NOT NULL,
    "recipient_user_id" bigint NOT NULL,
    "patient_profile_id" bigint NULL,
    "body" text NOT NULL,
    "read_at" timestamp(0) without time zone NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "doctor_messages_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE SET NULL,
    CONSTRAINT "doctor_messages_sender_user_id_foreign"
        FOREIGN KEY ("sender_user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "doctor_messages_recipient_user_id_foreign"
        FOREIGN KEY ("recipient_user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "doctor_messages_patient_profile_id_foreign"
        FOREIGN KEY ("patient_profile_id") REFERENCES "patient_profiles" ("id") ON DELETE SET NULL
);

-- PostgreSQL truncates the Laravel-generated 64-character index name to 63 bytes.
CREATE INDEX "doctor_messages_clinic_id_sender_user_id_recipient_user_id_inde"
    ON "doctor_messages" ("clinic_id", "sender_user_id", "recipient_user_id");
CREATE INDEX "doctor_messages_patient_profile_id_created_at_index"
    ON "doctor_messages" ("patient_profile_id", "created_at");

CREATE TABLE "clinic_receptionist_settings" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NOT NULL,
    "user_id" bigint NOT NULL,
    "preferences" json NOT NULL,
    "created_at" timestamp(0) without time zone NULL,
    "updated_at" timestamp(0) without time zone NULL,
    CONSTRAINT "clinic_receptionist_settings_clinic_id_foreign"
        FOREIGN KEY ("clinic_id") REFERENCES "clinics" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinic_receptionist_settings_user_id_foreign"
        FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
    CONSTRAINT "clinic_receptionist_settings_clinic_user_unique"
        UNIQUE ("clinic_id", "user_id")
);

-- ---------------------------------------------------------------------------
-- PostgreSQL CHECK constraints from the active migrations
-- ---------------------------------------------------------------------------

-- These constraints are added NOT VALID and then validated, matching the
-- PostgreSQL-specific migration behavior while remaining safe on an empty DB.

ALTER TABLE "users"
    ADD CONSTRAINT "users_status_check"
    CHECK ("status" IN ('pending_verification', 'active', 'suspended', 'disabled'))
    NOT VALID;
ALTER TABLE "users" VALIDATE CONSTRAINT "users_status_check";

ALTER TABLE "users"
    ADD CONSTRAINT "users_onboarding_status_check"
    CHECK ("onboarding_status" IN ('phone_verification', 'plan_selection', 'completed'))
    NOT VALID;
ALTER TABLE "users" VALIDATE CONSTRAINT "users_onboarding_status_check";

ALTER TABLE "otp_challenges"
    ADD CONSTRAINT "otp_challenges_purpose_check"
    CHECK ("purpose" IN ('registration', 'login', 'password_reset'))
    NOT VALID;
ALTER TABLE "otp_challenges" VALIDATE CONSTRAINT "otp_challenges_purpose_check";

ALTER TABLE "otp_challenges"
    ADD CONSTRAINT "otp_challenges_client_type_check"
    CHECK ("client_type" IN ('web', 'mobile'))
    NOT VALID;
ALTER TABLE "otp_challenges" VALIDATE CONSTRAINT "otp_challenges_client_type_check";

ALTER TABLE "otp_challenges"
    ADD CONSTRAINT "otp_challenges_actor_context_check"
    CHECK ("actor_context" IN ('patient', 'staff', 'clinic_admin', 'receptionist', 'doctor'))
    NOT VALID;
ALTER TABLE "otp_challenges" VALIDATE CONSTRAINT "otp_challenges_actor_context_check";

ALTER TABLE "otp_challenges"
    ADD CONSTRAINT "otp_challenges_delivery_status_check"
    CHECK ("delivery_status" IN ('pending', 'sent', 'failed'))
    NOT VALID;
ALTER TABLE "otp_challenges" VALIDATE CONSTRAINT "otp_challenges_delivery_status_check";

ALTER TABLE "authentication_sessions"
    ADD CONSTRAINT "authentication_sessions_actor_context_check"
    CHECK ("actor_context" IN ('patient', 'staff', 'clinic_admin', 'receptionist', 'doctor'))
    NOT VALID;
ALTER TABLE "authentication_sessions"
    VALIDATE CONSTRAINT "authentication_sessions_actor_context_check";

ALTER TABLE "authentication_events"
    ADD CONSTRAINT "authentication_events_outcome_check"
    CHECK ("outcome" IN ('success', 'failure'))
    NOT VALID;
ALTER TABLE "authentication_events"
    VALIDATE CONSTRAINT "authentication_events_outcome_check";

ALTER TABLE "roles"
    ADD CONSTRAINT "roles_key_check"
    CHECK ("key" IN ('super_admin', 'clinic_admin', 'receptionist', 'doctor'))
    NOT VALID;
ALTER TABLE "roles" VALIDATE CONSTRAINT "roles_key_check";

ALTER TABLE "roles"
    ADD CONSTRAINT "roles_scope_check"
    CHECK ("scope" IN ('global', 'organization'))
    NOT VALID;
ALTER TABLE "roles" VALIDATE CONSTRAINT "roles_scope_check";

ALTER TABLE "plans"
    ADD CONSTRAINT "plans_key_check"
    CHECK ("key" IN ('basic', 'professional', 'enterprise'))
    NOT VALID;
ALTER TABLE "plans" VALIDATE CONSTRAINT "plans_key_check";

ALTER TABLE "clinics"
    ADD CONSTRAINT "clinics_status_check"
    CHECK ("status" IN ('active', 'suspended'))
    NOT VALID;
ALTER TABLE "clinics" VALIDATE CONSTRAINT "clinics_status_check";

ALTER TABLE "clinic_memberships"
    ADD CONSTRAINT "clinic_memberships_status_check"
    CHECK ("status" IN ('active', 'suspended'))
    NOT VALID;
ALTER TABLE "clinic_memberships" VALIDATE CONSTRAINT "clinic_memberships_status_check";

ALTER TABLE "clinic_subscriptions"
    ADD CONSTRAINT "clinic_subscriptions_billing_cycle_check"
    CHECK ("billing_cycle" IN ('monthly', 'annual'))
    NOT VALID;
ALTER TABLE "clinic_subscriptions"
    VALIDATE CONSTRAINT "clinic_subscriptions_billing_cycle_check";

ALTER TABLE "clinic_subscriptions"
    ADD CONSTRAINT "clinic_subscriptions_status_check"
    CHECK ("status" IN ('active', 'past_due', 'canceled', 'expired'))
    NOT VALID;
ALTER TABLE "clinic_subscriptions" VALIDATE CONSTRAINT "clinic_subscriptions_status_check";

ALTER TABLE "doctor_profiles"
    ADD CONSTRAINT "doctor_profiles_practice_type_check"
    CHECK ("practice_type" IN ('clinic', 'independent'))
    NOT VALID;
ALTER TABLE "doctor_profiles" VALIDATE CONSTRAINT "doctor_profiles_practice_type_check";

ALTER TABLE "doctor_subscriptions"
    ADD CONSTRAINT "doctor_subscriptions_billing_cycle_check"
    CHECK ("billing_cycle" IN ('monthly', 'annual'))
    NOT VALID;
ALTER TABLE "doctor_subscriptions"
    VALIDATE CONSTRAINT "doctor_subscriptions_billing_cycle_check";

ALTER TABLE "doctor_subscriptions"
    ADD CONSTRAINT "doctor_subscriptions_status_check"
    CHECK ("status" IN ('active', 'past_due', 'canceled', 'expired'))
    NOT VALID;
ALTER TABLE "doctor_subscriptions"
    VALIDATE CONSTRAINT "doctor_subscriptions_status_check";

ALTER TABLE "clinic_invoices"
    ADD CONSTRAINT "clinic_invoices_total_minor_check"
    CHECK ("total_minor" > 0)
    NOT VALID;
ALTER TABLE "clinic_invoices" VALIDATE CONSTRAINT "clinic_invoices_total_minor_check";

ALTER TABLE "clinic_invoices"
    ADD CONSTRAINT "clinic_invoices_currency_check"
    CHECK ("currency" ~ '^[A-Z]{3}$')
    NOT VALID;
ALTER TABLE "clinic_invoices" VALIDATE CONSTRAINT "clinic_invoices_currency_check";

ALTER TABLE "clinic_invoices"
    ADD CONSTRAINT "clinic_invoices_status_check"
    CHECK ("status" IN ('issued', 'partially_paid', 'paid', 'voided'))
    NOT VALID;
ALTER TABLE "clinic_invoices" VALIDATE CONSTRAINT "clinic_invoices_status_check";

ALTER TABLE "clinic_invoice_items"
    ADD CONSTRAINT "clinic_invoice_items_quantity_check"
    CHECK ("quantity" > 0)
    NOT VALID;
ALTER TABLE "clinic_invoice_items"
    VALIDATE CONSTRAINT "clinic_invoice_items_quantity_check";

ALTER TABLE "clinic_invoice_items"
    ADD CONSTRAINT "clinic_invoice_items_unit_amount_check"
    CHECK ("unit_amount_minor" > 0)
    NOT VALID;
ALTER TABLE "clinic_invoice_items"
    VALIDATE CONSTRAINT "clinic_invoice_items_unit_amount_check";

ALTER TABLE "clinic_invoice_items"
    ADD CONSTRAINT "clinic_invoice_items_total_amount_check"
    CHECK ("line_total_minor" > 0)
    NOT VALID;
ALTER TABLE "clinic_invoice_items"
    VALIDATE CONSTRAINT "clinic_invoice_items_total_amount_check";

ALTER TABLE "clinic_invoice_payments"
    ADD CONSTRAINT "clinic_invoice_payments_amount_check"
    CHECK ("amount_minor" > 0)
    NOT VALID;
ALTER TABLE "clinic_invoice_payments"
    VALIDATE CONSTRAINT "clinic_invoice_payments_amount_check";

ALTER TABLE "clinic_invoice_payments"
    ADD CONSTRAINT "clinic_invoice_payments_currency_check"
    CHECK ("currency" ~ '^[A-Z]{3}$')
    NOT VALID;
ALTER TABLE "clinic_invoice_payments"
    VALIDATE CONSTRAINT "clinic_invoice_payments_currency_check";

ALTER TABLE "clinic_invoice_payments"
    ADD CONSTRAINT "clinic_invoice_payments_status_check"
    CHECK ("status" IN ('confirmed', 'voided'))
    NOT VALID;
ALTER TABLE "clinic_invoice_payments"
    VALIDATE CONSTRAINT "clinic_invoice_payments_status_check";

COMMIT;
