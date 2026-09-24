<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 30);
            $table->string('kind', 60);
            $table->string('status', 30)->default('pending');
            $table->string('provider_reference')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['appointment_id', 'channel', 'kind'], 'appointment_delivery_unique');
            $table->index(['channel', 'status', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_notification_deliveries');
    }
};
