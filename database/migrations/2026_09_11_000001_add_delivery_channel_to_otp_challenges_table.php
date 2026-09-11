<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->string('delivery_channel')->default('phone')->after('actor_context')->index();
        });
    }

    public function down(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table) {
            $table->dropColumn('delivery_channel');
        });
    }
};
