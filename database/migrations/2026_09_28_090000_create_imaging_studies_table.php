<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imaging_studies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('accession_number', 64)->unique();
            $table->string('study_instance_uid', 128)->nullable()->unique();
            $table->string('modality', 16);
            $table->string('study_description', 180);
            $table->string('status', 24)->index();
            $table->string('pacs_provider', 60)->nullable();
            $table->string('pacs_study_key', 255)->nullable();
            $table->text('report_summary')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('ordered_at')->index();
            $table->timestamp('study_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['patient_profile_id', 'ordered_at']);
            $table->index(['doctor_user_id', 'status', 'ordered_at']);
            $table->index(['clinic_id', 'status', 'ordered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imaging_studies');
    }
};
