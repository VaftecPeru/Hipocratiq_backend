<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_intakes', function (Blueprint $table): void {
            $table->decimal('temperature_c', 4, 1)->nullable()->after('observations');
            $table->unsignedSmallInteger('systolic_bp')->nullable()->after('temperature_c');
            $table->unsignedSmallInteger('diastolic_bp')->nullable()->after('systolic_bp');
            $table->unsignedSmallInteger('heart_rate')->nullable()->after('diastolic_bp');
            $table->unsignedSmallInteger('respiratory_rate')->nullable()->after('heart_rate');
            $table->unsignedSmallInteger('oxygen_saturation')->nullable()->after('respiratory_rate');
            $table->string('triage_priority', 20)->nullable()->after('oxygen_saturation');
            $table->text('triage_notes')->nullable()->after('triage_priority');
        });
    }

    public function down(): void
    {
        Schema::table('patient_intakes', function (Blueprint $table): void {
            $table->dropColumn([
                'temperature_c',
                'systolic_bp',
                'diastolic_bp',
                'heart_rate',
                'respiratory_rate',
                'oxygen_saturation',
                'triage_priority',
                'triage_notes',
            ]);
        });
    }
};
