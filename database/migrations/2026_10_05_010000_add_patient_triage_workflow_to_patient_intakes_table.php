<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_intakes', function (Blueprint $table): void {
            $table->string('triage_level', 8)->nullable()->after('triage_priority');
            $table->string('triage_status', 20)->default('submitted')->after('triage_level');
            $table->timestamp('submitted_at')->nullable()->after('triage_status');

            $table->index(['patient_profile_id', 'triage_status']);
        });
    }

    public function down(): void
    {
        Schema::table('patient_intakes', function (Blueprint $table): void {
            $table->dropIndex(['patient_profile_id', 'triage_status']);
            $table->dropColumn(['triage_level', 'triage_status', 'submitted_at']);
        });
    }
};
