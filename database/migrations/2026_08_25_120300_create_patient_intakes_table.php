<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_intakes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registered_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requested_doctor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('specialty_id')->nullable()->constrained('specialties')->nullOnDelete();
            $table->foreignId('subspecialty_id')->nullable()->constrained('specialties')->nullOnDelete();
            $table->string('reason_category', 80)->nullable();
            $table->text('objective');
            $table->text('observations')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'patient_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_intakes');
    }
};
