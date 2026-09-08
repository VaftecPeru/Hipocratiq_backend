<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->text('reconciliation_note')->nullable()->after('provider_reference');
            $table->string('refund_reference')->nullable()->unique()->after('reconciliation_note');
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table): void {
            $table->dropUnique(['refund_reference']);
            $table->dropColumn(['reconciliation_note', 'refund_reference', 'refunded_at']);
        });
    }
};
