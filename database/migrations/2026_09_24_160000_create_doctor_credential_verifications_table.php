<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_credential_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_profile_id')->constrained()->cascadeOnDelete();
            $table->string('credential_type', 30);
            $table->string('credential_value', 80);
            $table->string('status', 30);
            $table->string('reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at');
            $table->timestamps();

            $table->index(['doctor_profile_id', 'credential_type', 'verified_at'], 'doctor_credential_history_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_credential_verifications');
    }
};
