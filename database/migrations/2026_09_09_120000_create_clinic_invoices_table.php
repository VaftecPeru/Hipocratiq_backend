<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_profile_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number', 64);
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('total_minor');
            $table->string('currency', 3);
            $table->string('status', 32)->default('issued')->index();
            $table->timestamps();

            $table->unique(['clinic_id', 'invoice_number'], 'clinic_invoice_number_unique');
            $table->index(['clinic_id', 'issue_date'], 'clinic_invoice_issue_date_index');
            $table->index(['clinic_id', 'status', 'issue_date'], 'clinic_invoice_status_date_index');
            $table->index(['clinic_id', 'patient_profile_id', 'issue_date'], 'clinic_invoice_patient_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_invoices');
    }
};
