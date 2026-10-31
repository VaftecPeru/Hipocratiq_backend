<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ningún proveedor puede consultar información clínica mientras esté pendiente.
        Schema::create('diagnostic_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_type', 32);
            $table->string('name', 160);
            $table->string('tax_id', 80);
            $table->string('country_code', 2)->default('MX');
            $table->string('contact_name', 160);
            $table->string('contact_email', 254);
            $table->string('contact_phone', 32);
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['country_code', 'tax_id'], 'diagnostic_provider_country_tax_unique');
            $table->index(['provider_type', 'status']);
        });

        Schema::create('diagnostic_provider_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('diagnostic_provider_id')->constrained('diagnostic_providers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 24); // admin, technician (autorización futura por política)
            $table->string('status', 24)->default('pending')->index();
            $table->timestamps();

            $table->unique(['diagnostic_provider_id', 'user_id'], 'diagnostic_provider_member_unique');
        });

        Schema::create('diagnostic_clinic_affiliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('diagnostic_provider_id')->constrained('diagnostic_providers')->cascadeOnDelete();
            $table->foreignId('clinic_id')->constrained('clinics')->cascadeOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['diagnostic_provider_id', 'clinic_id'], 'diagnostic_provider_clinic_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_clinic_affiliations');
        Schema::dropIfExists('diagnostic_provider_memberships');
        Schema::dropIfExists('diagnostic_providers');
    }
};
