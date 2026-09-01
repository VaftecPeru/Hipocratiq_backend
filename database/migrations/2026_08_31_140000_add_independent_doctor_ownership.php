<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Independent doctors do not have a clinic, so their clinical records
     * need a nullable clinic relation and an explicit patient owner.
     */
    public function up(): void
    {
        Schema::table('patient_profiles', function (Blueprint $table): void {
            $table->foreignId('owner_doctor_user_id')
                ->nullable()
                ->after('clinic_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['owner_doctor_user_id', 'id']);
        });

        foreach (['appointments', 'clinical_history_records', 'consultations'] as $tableName) {
            $this->makeClinicNullable($tableName);
        }
    }

    public function down(): void
    {
        foreach (['appointments', 'clinical_history_records', 'consultations'] as $tableName) {
            if (DB::table($tableName)->whereNull('clinic_id')->exists()) {
                throw new RuntimeException("Cannot make {$tableName}.clinic_id required while independent records exist.");
            }

            $this->makeClinicRequired($tableName);
        }

        Schema::table('patient_profiles', function (Blueprint $table): void {
            $table->dropIndex(['owner_doctor_user_id', 'id']);
            $table->dropConstrainedForeignId('owner_doctor_user_id');
        });
    }

    private function makeClinicNullable(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropForeign(['clinic_id']);
            $table->unsignedBigInteger('clinic_id')->nullable()->change();
        });

        Schema::table($tableName, function (Blueprint $table): void {
            $table->foreign('clinic_id')->references('id')->on('clinics')->cascadeOnDelete();
        });
    }

    private function makeClinicRequired(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropForeign(['clinic_id']);
            $table->unsignedBigInteger('clinic_id')->nullable(false)->change();
        });

        Schema::table($tableName, function (Blueprint $table): void {
            $table->foreign('clinic_id')->references('id')->on('clinics')->cascadeOnDelete();
        });
    }
};
