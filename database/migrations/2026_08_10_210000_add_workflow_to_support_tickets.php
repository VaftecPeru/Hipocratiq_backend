<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->timestamp('due_at')->nullable()->index();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
        });

        Schema::create('support_ticket_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 40)->index();
            $table->text('body')->nullable();
            $table->string('from_value', 120)->nullable();
            $table->string('to_value', 120)->nullable();
            $table->boolean('is_internal')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_activities');

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->dropColumn([
                'due_at',
                'first_responded_at',
                'resolved_at',
                'closed_at',
                'last_activity_at',
            ]);
        });
    }
};
