<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->char('medical_license_country_code', 2)->nullable()->after('medical_license');
            $table->string('medical_license_issuer', 120)->nullable()->after('medical_license_country_code');
            $table->string('medical_license_verification_status', 30)->default('unverified')->after('medical_license_issuer');
            $table->timestamp('medical_license_verified_at')->nullable()->after('medical_license_verification_status');
            $table->string('medical_license_verification_reference', 255)->nullable()->after('medical_license_verified_at');
            $table->string('specialty_license', 80)->nullable()->after('subspecialty_id');
            $table->string('specialty_license_verification_status', 30)->default('not_applicable')->after('specialty_license');
            $table->timestamp('specialty_license_verified_at')->nullable()->after('specialty_license_verification_status');
            $table->string('specialty_license_verification_reference', 255)->nullable()->after('specialty_license_verified_at');
        });

        Schema::table('consultations', function (Blueprint $table): void {
            $table->string('signed_doctor_name', 120)->nullable()->after('signed_by_user_id');
            $table->string('signed_doctor_medical_license', 80)->nullable()->after('signed_doctor_name');
            $table->char('signed_doctor_license_country_code', 2)->nullable()->after('signed_doctor_medical_license');
            $table->string('signed_doctor_license_issuer', 120)->nullable()->after('signed_doctor_license_country_code');
            $table->string('signed_doctor_specialty_license', 80)->nullable()->after('signed_doctor_license_issuer');
            $table->string('signed_doctor_specialty', 120)->nullable()->after('signed_doctor_specialty_license');
            $table->string('signature_digest', 64)->nullable()->after('signed_at');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->dropColumn([
                'signed_doctor_name',
                'signed_doctor_medical_license',
                'signed_doctor_license_country_code',
                'signed_doctor_license_issuer',
                'signed_doctor_specialty_license',
                'signed_doctor_specialty',
                'signature_digest',
            ]);
        });

        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'medical_license_country_code',
                'medical_license_issuer',
                'medical_license_verification_status',
                'medical_license_verified_at',
                'medical_license_verification_reference',
                'specialty_license',
                'specialty_license_verification_status',
                'specialty_license_verified_at',
                'specialty_license_verification_reference',
            ]);
        });
    }
};
