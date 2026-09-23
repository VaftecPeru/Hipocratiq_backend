<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_intake_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete();
            $table->json('sections');
            $table->timestamp('declaration_accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['patient_profile_id', 'clinic_id'], 'patient_intake_drafts_patient_clinic_unique');
            $table->index(['clinic_id', 'patient_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_intake_drafts');
    }
};
