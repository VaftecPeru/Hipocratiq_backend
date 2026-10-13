<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->string('verification_provider', 32)->nullable()->after('delivery_channel')->index();
            $table->string('provider_message_id')->nullable()->after('verification_provider');
            $table->string('provider_verification_id')->nullable()->after('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->dropIndex(['verification_provider']);
            $table->dropColumn([
                'verification_provider',
                'provider_message_id',
                'provider_verification_id',
            ]);
        });
    }
};
