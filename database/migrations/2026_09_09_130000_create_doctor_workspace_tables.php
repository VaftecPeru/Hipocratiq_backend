<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_prescriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('medication', 180);
            $table->string('dosage', 120)->nullable();
            $table->string('frequency', 120)->nullable();
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'doctor_user_id', 'status']);
            $table->index(['doctor_user_id', 'patient_profile_id']);
        });

        Schema::create('laboratory_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('study_name', 180);
            $table->string('status', 30)->default('requested');
            $table->string('priority', 20)->default('normal');
            $table->boolean('is_critical')->default(false);
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('result_at')->nullable();
            $table->text('result_summary')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'doctor_user_id', 'status']);
            $table->index(['doctor_user_id', 'patient_profile_id']);
        });

        Schema::create('teleconsultations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('scheduled');
            $table->timestamp('scheduled_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'doctor_user_id', 'status']);
            $table->index(['doctor_user_id', 'scheduled_at']);
        });

        Schema::create('doctor_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'sender_user_id', 'recipient_user_id']);
            $table->index(['patient_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_messages');
        Schema::dropIfExists('teleconsultations');
        Schema::dropIfExists('laboratory_orders');
        Schema::dropIfExists('doctor_prescriptions');
    }
};
