<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_receptionist_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('preferences');
            $table->timestamps();

            $table->unique(['clinic_id', 'user_id'], 'clinic_receptionist_settings_clinic_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_receptionist_settings');
    }
};
