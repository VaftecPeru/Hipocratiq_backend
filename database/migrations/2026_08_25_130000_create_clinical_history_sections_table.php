<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_history_sections', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('title', 160);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->json('content_schema');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_history_sections');
    }
};
