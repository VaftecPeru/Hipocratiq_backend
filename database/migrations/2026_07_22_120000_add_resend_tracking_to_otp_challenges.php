<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->uuid('flow_id')->nullable()->after('id')->index();
            $table->unsignedTinyInteger('resend_count')->default(0)->after('max_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->dropIndex(['flow_id']);
            $table->dropColumn(['flow_id', 'resend_count']);
        });
    }
};
