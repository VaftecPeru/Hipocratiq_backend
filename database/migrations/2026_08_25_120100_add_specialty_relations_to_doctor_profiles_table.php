<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->foreignId('specialty_id')->nullable()->after('specialty')->constrained('specialties')->nullOnDelete();
            $table->foreignId('subspecialty_id')->nullable()->after('specialty_id')->constrained('specialties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('subspecialty_id');
            $table->dropConstrainedForeignId('specialty_id');
        });
    }
};
