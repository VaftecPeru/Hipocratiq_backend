<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->string('platform_name', 80)->default('MEDIQ');
            $table->string('support_email')->nullable();
            $table->string('support_url')->nullable();
            $table->string('default_locale', 10)->default('es-MX');
            $table->json('enabled_locales')->nullable();
            $table->json('ai_configuration')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->dropColumn(['platform_name', 'support_email', 'support_url', 'default_locale', 'enabled_locales', 'ai_configuration']);
        });
    }
};
