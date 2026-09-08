<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->json('emergency_policy')->nullable();
            $table->json('emergency_responsibles')->nullable();
            $table->timestamp('emergency_published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->dropColumn(['emergency_policy', 'emergency_responsibles', 'emergency_published_at']);
        });
    }
};
