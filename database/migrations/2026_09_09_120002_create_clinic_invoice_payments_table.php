<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_invoice_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 32)->default('confirmed')->index();
            $table->string('payment_method', 32);
            $table->string('reference', 120)->nullable()->unique();
            $table->text('note')->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['clinic_invoice_id', 'status', 'paid_at'], 'clinic_invoice_payment_status_index');
            $table->index(['currency', 'paid_at'], 'clinic_invoice_payment_currency_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_invoice_payments');
    }
};
