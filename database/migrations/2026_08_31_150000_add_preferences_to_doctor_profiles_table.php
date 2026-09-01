<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->json('preferences')->nullable()->after('practice_type');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->dropColumn('preferences');
        });
    }
};
