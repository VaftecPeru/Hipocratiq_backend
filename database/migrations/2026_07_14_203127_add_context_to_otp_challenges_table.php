<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->string('client_type')->default('web')->after('purpose');
            $table->string('actor_context')->default('patient')->after('client_type');
            $table->string('device_name')->nullable()->after('actor_context');
            $table->string('device_id_hash', 64)->nullable()->after('device_name');
            $table->string('code_hash', 64)->nullable()->change();

            $table->index(['user_id', 'client_type', 'actor_context']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('otp_challenges')->whereNull('code_hash')->update([
            'code_hash' => str_repeat('0', 64),
        ]);

        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'client_type', 'actor_context']);
            $table->dropColumn(['client_type', 'actor_context', 'device_name', 'device_id_hash']);
            $table->string('code_hash', 64)->nullable(false)->change();
        });
    }
};
