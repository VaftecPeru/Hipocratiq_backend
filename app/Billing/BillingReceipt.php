<?php

namespace App\Billing;

use Carbon\CarbonImmutable;

final readonly class BillingReceipt
{
    public function __construct(
        public string $provider,
        public string $subscriptionReference,
        public string $paymentReference,
        public CarbonImmutable $paidAt,
    ) {}
}
