<?php

namespace App\Billing;

final readonly class SubscriptionPurchase
{
    public function __construct(
        public string $idempotencyKey,
        public int $amountCents,
        public string $currency,
        public string $subjectType,
        public string $planKey,
        public string $billingCycle,
    ) {}
}
