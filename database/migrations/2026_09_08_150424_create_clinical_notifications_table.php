<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('event_key', 64);
            $table->string('source_type', 64);
            $table->string('source_id', 128);
            $table->string('title_key', 160);
            $table->string('message_key', 160);
            $table->string('severity', 16);
            $table->string('href', 255);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'clinic_id', 'source_type', 'source_id'],
                'clinical_notifications_recipient_source_unique',
            );
            $table->index(
                ['user_id', 'clinic_id', 'read_at', 'occurred_at'],
                'clinical_notifications_inbox_index',
            );
            $table->index(['clinic_id', 'occurred_at'], 'clinical_notifications_clinic_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_notifications');
    }
};
