<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_history_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinical_history_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clinical_history_section_id')->constrained()->cascadeOnDelete();
            $table->json('values');
            $table->timestamps();
            $table->unique(['clinical_history_record_id', 'clinical_history_section_id'], 'clinical_history_entry_record_section_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_history_entries');
    }
};
