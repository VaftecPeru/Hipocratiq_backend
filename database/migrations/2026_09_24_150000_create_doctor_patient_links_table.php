<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_patient_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('linked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('source', 40)->default('doctor_registration');
            $table->timestamps();
            $table->unique(['clinic_id', 'doctor_user_id', 'patient_profile_id'], 'doctor_patient_link_unique');
            $table->index(['doctor_user_id', 'patient_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_patient_links');
    }
};
