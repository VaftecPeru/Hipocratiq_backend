<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, array{table: string, expression: string}>
     */
    private const array Constraints = [
        'clinic_invoices_total_minor_check' => [
            'table' => 'clinic_invoices',
            'expression' => 'total_minor > 0',
        ],
        'clinic_invoices_currency_check' => [
            'table' => 'clinic_invoices',
            'expression' => "currency ~ '^[A-Z]{3}$'",
        ],
        'clinic_invoices_status_check' => [
            'table' => 'clinic_invoices',
            'expression' => "status IN ('issued', 'partially_paid', 'paid', 'voided')",
        ],
        'clinic_invoice_items_quantity_check' => [
            'table' => 'clinic_invoice_items',
            'expression' => 'quantity > 0',
        ],
        'clinic_invoice_items_unit_amount_check' => [
            'table' => 'clinic_invoice_items',
            'expression' => 'unit_amount_minor > 0',
        ],
        'clinic_invoice_items_total_amount_check' => [
            'table' => 'clinic_invoice_items',
            'expression' => 'line_total_minor > 0',
        ],
        'clinic_invoice_payments_amount_check' => [
            'table' => 'clinic_invoice_payments',
            'expression' => 'amount_minor > 0',
        ],
        'clinic_invoice_payments_currency_check' => [
            'table' => 'clinic_invoice_payments',
            'expression' => "currency ~ '^[A-Z]{3}$'",
        ],
        'clinic_invoice_payments_status_check' => [
            'table' => 'clinic_invoice_payments',
            'expression' => "status IN ('confirmed', 'voided')",
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::Constraints as $name => $constraint) {
            DB::statement(sprintf(
                'ALTER TABLE "%s" ADD CONSTRAINT "%s" CHECK (%s) NOT VALID',
                $constraint['table'],
                $name,
                $constraint['expression'],
            ));
            DB::statement(sprintf(
                'ALTER TABLE "%s" VALIDATE CONSTRAINT "%s"',
                $constraint['table'],
                $name,
            ));
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(self::Constraints, true) as $name => $constraint) {
            DB::statement(sprintf(
                'ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS "%s"',
                $constraint['table'],
                $name,
            ));
        }
    }
};
