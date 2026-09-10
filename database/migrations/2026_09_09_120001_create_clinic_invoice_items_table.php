<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinic_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description', 200);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_amount_minor');
            $table->unsignedBigInteger('line_total_minor');
            $table->timestamps();

            $table->index(['clinic_invoice_id'], 'clinic_invoice_item_invoice_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_invoice_items');
    }
};
