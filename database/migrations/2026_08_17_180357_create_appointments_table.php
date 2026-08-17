<?php

use App\Enums\AppointmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('status')->default(AppointmentStatus::Pending->value);
            $table->string('appointment_type')->default('consultation');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'scheduled_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
