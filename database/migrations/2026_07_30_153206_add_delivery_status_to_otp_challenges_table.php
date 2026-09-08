<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->string('delivery_status')->default('sent')->after('resend_count');
            $table->unsignedInteger('delivery_sequence')->default(1)->after('delivery_status');
            $table->index(
                ['user_id', 'purpose', 'delivery_sequence'],
                'otp_challenges_delivery_sequence_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->dropIndex('otp_challenges_delivery_sequence_index');
            $table->dropColumn(['delivery_status', 'delivery_sequence']);
        });
    }
};
