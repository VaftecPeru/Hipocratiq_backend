<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_profiles', function (Blueprint $table): void {
            $table->string('document_type', 40)->nullable()->after('clinic_id');
            $table->string('document_number', 80)->nullable()->after('document_type');
            $table->date('date_of_birth')->nullable()->after('document_number');
            $table->string('sex', 40)->nullable()->after('date_of_birth');
            $table->string('address', 240)->nullable()->after('sex');
            $table->string('emergency_contact', 160)->nullable()->after('address');
            $table->index(['clinic_id', 'document_type', 'document_number']);
        });
    }

    public function down(): void
    {
        Schema::table('patient_profiles', function (Blueprint $table): void {
            $table->dropIndex(['clinic_id', 'document_type', 'document_number']);
            $table->dropColumn(['document_type', 'document_number', 'date_of_birth', 'sex', 'address', 'emergency_contact']);
        });
    }
};
