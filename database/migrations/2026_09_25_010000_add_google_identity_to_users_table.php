<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_sub', 255)->nullable()->unique()->after('email');
            $table->string('google_avatar_url', 1024)->nullable()->after('google_sub');
            $table->timestamp('google_linked_at')->nullable()->after('google_avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['google_sub', 'google_avatar_url', 'google_linked_at']);
        });
    }
};
