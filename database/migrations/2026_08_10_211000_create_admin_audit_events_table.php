<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete();
            $table->char('country_code', 2)->nullable()->index();
            $table->string('module', 60)->index();
            $table->string('action', 80)->index();
            $table->boolean('is_critical')->default(false)->index();
            $table->string('resource_type', 120)->nullable();
            $table->string('resource_id', 80)->nullable();
            $table->string('resource_label', 180)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['module', 'occurred_at']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['clinic_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_events');
    }
};
