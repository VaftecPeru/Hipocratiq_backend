<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_profiles', function (Blueprint $table): void {
            $table->string('insurance', 120)->nullable()->after('emergency_contact');
            $table->boolean('is_returning_patient')->default(false)->after('insurance');
            $table->boolean('whatsapp_consent')->default(false)->after('is_returning_patient');
        });
    }

    public function down(): void
    {
        Schema::table('patient_profiles', function (Blueprint $table): void {
            $table->dropColumn(['insurance', 'is_returning_patient', 'whatsapp_consent']);
        });
    }
};
