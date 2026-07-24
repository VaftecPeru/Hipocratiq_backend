<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table): void {
            $table->index(['status', 'name'], 'clinics_registration_lookup_index');
        });

        Schema::table('clinic_subscriptions', function (Blueprint $table): void {
            $table->index(
                ['clinic_id', 'status', 'ends_at'],
                'clinic_subscriptions_access_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('clinic_subscriptions', function (Blueprint $table): void {
            $table->dropIndex('clinic_subscriptions_access_lookup_index');
        });

        Schema::table('clinics', function (Blueprint $table): void {
            $table->dropIndex('clinics_registration_lookup_index');
        });
    }
};
