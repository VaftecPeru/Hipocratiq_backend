<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('client_type')->default('web')->after('abilities');
            $table->string('actor_context')->nullable()->after('client_type');
            $table->string('device_name', 100)->nullable()->after('actor_context');
            $table->string('device_id_hash', 64)->nullable()->after('device_name');
            $table->index(
                ['tokenable_id', 'client_type', 'device_id_hash'],
                'personal_access_tokens_mobile_device_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex('personal_access_tokens_mobile_device_index');
            $table->dropColumn(['client_type', 'actor_context', 'device_name', 'device_id_hash']);
        });
    }
};
