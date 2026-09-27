<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope', 80)->index();
            $table->string('policy_version', 40);
            $table->string('status', 20)->default('granted')->index();
            $table->string('source', 40)->default('digital');
            $table->timestamp('effective_from')->index();
            $table->timestamp('effective_until')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['patient_profile_id', 'scope', 'status']);
            $table->index(['clinic_id', 'scope', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_consents');
    }
};
