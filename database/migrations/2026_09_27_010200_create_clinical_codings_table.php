<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_codings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 40)->index();
            $table->string('system', 255);
            $table->string('code', 120);
            $table->string('display', 255);
            $table->string('status', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->unique(['consultation_id', 'category', 'system', 'code'], 'clinical_codings_unique_concept');
            $table->index(['patient_profile_id', 'category', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_codings');
    }
};
