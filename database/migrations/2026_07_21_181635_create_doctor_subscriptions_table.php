<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('billing_cycle')->index();
            $table->string('status')->default('active')->index();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('provider')->default('simulated');
            $table->string('provider_reference')->unique();
            $table->timestamps();

            $table->index(['doctor_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_subscriptions');
    }
};
