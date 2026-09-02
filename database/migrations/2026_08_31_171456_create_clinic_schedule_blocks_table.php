<?php

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
         Schema::create('clinic_schedule_blocks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('clinic_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('doctor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('created_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            $table->string('reason', 500)->nullable();

            $table->timestamps();

            $table->index([
                'clinic_id',
                'starts_at',
                'ends_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinic_schedule_blocks');
    }
};

