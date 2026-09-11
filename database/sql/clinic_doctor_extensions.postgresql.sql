-- PostgreSQL deployment DDL for clinic administration and doctor workspace extensions.
--
-- Covered Laravel migrations:
--   2026_09_09_120000_create_clinic_invoices_table.php
--   2026_09_09_120001_create_clinic_invoice_items_table.php
--   2026_09_09_120002_create_clinic_invoice_payments_table.php
--   2026_09_09_120003_add_clinic_billing_check_constraints.php
--   2026_09_09_130000_create_doctor_workspace_tables.php
--   2026_09_09_140000_create_clinic_receptionist_settings_table.php
--
-- Prerequisites: the existing users, clinics, patient_profiles, appointments, and
-- consultations tables must already exist in the active PostgreSQL schema.
-- This script creates schema objects only; it inserts no data and contains no PII
-- or credentials.
--
-- Rollback note: drop objects in reverse dependency order. Dropping a table also
-- removes its local foreign keys, check constraints, indexes, and sequences.
--
-- DROP TABLE "clinic_receptionist_settings";
-- DROP TABLE "doctor_messages";
-- DROP TABLE "teleconsultations";
-- DROP TABLE "laboratory_orders";
-- DROP TABLE "doctor_prescriptions";
-- DROP TABLE "clinic_invoice_payments";
-- DROP TABLE "clinic_invoice_items";
-- DROP TABLE "clinic_invoices";

BEGIN;

-- Clinic billing: invoices are tenant-scoped and belong to an existing patient profile.
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

CREATE INDEX "clinic_invoices_status_index"
    ON "clinic_invoices" ("status");
CREATE INDEX "clinic_invoice_issue_date_index"
    ON "clinic_invoices" ("clinic_id", "issue_date");
CREATE INDEX "clinic_invoice_status_date_index"
    ON "clinic_invoices" ("clinic_id", "status", "issue_date");
CREATE INDEX "clinic_invoice_patient_date_index"
    ON "clinic_invoices" ("clinic_id", "patient_profile_id", "issue_date");

-- Invoice line items depend on their parent invoice.
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

-- Invoice payments depend on their parent invoice and may retain the recording user.
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
    CONSTRAINT "clinic_invoice_payments_reference_unique"
        UNIQUE ("reference")
);

CREATE INDEX "clinic_invoice_payments_status_index"
    ON "clinic_invoice_payments" ("status");
CREATE INDEX "clinic_invoice_payment_status_index"
    ON "clinic_invoice_payments" ("clinic_invoice_id", "status", "paid_at");
CREATE INDEX "clinic_invoice_payment_currency_index"
    ON "clinic_invoice_payments" ("currency", "paid_at");

-- The Laravel migration adds and validates these PostgreSQL-only checks as NOT VALID first.
ALTER TABLE "clinic_invoices"
    ADD CONSTRAINT "clinic_invoices_total_minor_check"
    CHECK (total_minor > 0) NOT VALID;
ALTER TABLE "clinic_invoices"
    VALIDATE CONSTRAINT "clinic_invoices_total_minor_check";

ALTER TABLE "clinic_invoices"
    ADD CONSTRAINT "clinic_invoices_currency_check"
    CHECK (currency ~ '^[A-Z]{3}$') NOT VALID;
ALTER TABLE "clinic_invoices"
    VALIDATE CONSTRAINT "clinic_invoices_currency_check";

ALTER TABLE "clinic_invoices"
    ADD CONSTRAINT "clinic_invoices_status_check"
    CHECK (status IN ('issued', 'partially_paid', 'paid', 'voided')) NOT VALID;
ALTER TABLE "clinic_invoices"
    VALIDATE CONSTRAINT "clinic_invoices_status_check";

ALTER TABLE "clinic_invoice_items"
    ADD CONSTRAINT "clinic_invoice_items_quantity_check"
    CHECK (quantity > 0) NOT VALID;
ALTER TABLE "clinic_invoice_items"
    VALIDATE CONSTRAINT "clinic_invoice_items_quantity_check";

ALTER TABLE "clinic_invoice_items"
    ADD CONSTRAINT "clinic_invoice_items_unit_amount_check"
    CHECK (unit_amount_minor > 0) NOT VALID;
ALTER TABLE "clinic_invoice_items"
    VALIDATE CONSTRAINT "clinic_invoice_items_unit_amount_check";

ALTER TABLE "clinic_invoice_items"
    ADD CONSTRAINT "clinic_invoice_items_total_amount_check"
    CHECK (line_total_minor > 0) NOT VALID;
ALTER TABLE "clinic_invoice_items"
    VALIDATE CONSTRAINT "clinic_invoice_items_total_amount_check";

ALTER TABLE "clinic_invoice_payments"
    ADD CONSTRAINT "clinic_invoice_payments_amount_check"
    CHECK (amount_minor > 0) NOT VALID;
ALTER TABLE "clinic_invoice_payments"
    VALIDATE CONSTRAINT "clinic_invoice_payments_amount_check";

ALTER TABLE "clinic_invoice_payments"
    ADD CONSTRAINT "clinic_invoice_payments_currency_check"
    CHECK (currency ~ '^[A-Z]{3}$') NOT VALID;
ALTER TABLE "clinic_invoice_payments"
    VALIDATE CONSTRAINT "clinic_invoice_payments_currency_check";

ALTER TABLE "clinic_invoice_payments"
    ADD CONSTRAINT "clinic_invoice_payments_status_check"
    CHECK (status IN ('confirmed', 'voided')) NOT VALID;
ALTER TABLE "clinic_invoice_payments"
    VALIDATE CONSTRAINT "clinic_invoice_payments_status_check";

-- Doctor prescriptions support both clinic-linked and independent practice workflows.
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

-- Laboratory orders are authored by a doctor and may be linked to a consultation.
CREATE TABLE "laboratory_orders" (
    "id" bigserial NOT NULL PRIMARY KEY,
    "clinic_id" bigint NULL,
    "doctor_user_id" bigint NOT NULL,
    "patient_profile_id" bigint NOT NULL,
    "consultation_id" bigint NULL,
    "study_name" varchar(180) NOT NULL,
    "status" varchar(30) NOT NULL DEFAULT 'requested',
    "priority" varchar(20) NOT NULL DEFAULT 'normal',
    "is_critical" boolean NOT NULL DEFAULT '0',
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

-- Teleconsultations may be associated with an appointment and/or a consultation.
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

-- Doctor-to-doctor or doctor-to-care-team messages may optionally reference a patient.
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

-- PostgreSQL stores the Laravel-generated 64-character name truncated to 63 characters.
CREATE INDEX "doctor_messages_clinic_id_sender_user_id_recipient_user_id_inde"
    ON "doctor_messages" ("clinic_id", "sender_user_id", "recipient_user_id");
CREATE INDEX "doctor_messages_patient_profile_id_created_at_index"
    ON "doctor_messages" ("patient_profile_id", "created_at");

-- Receptionist preferences are unique per clinic and user.
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

COMMIT;
