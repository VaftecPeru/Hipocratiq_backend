<?php

namespace App\Services\Billing;

use App\Billing\BillingReceipt;
use App\Billing\SubscriptionPurchase;
use App\Contracts\BillingGateway;
use App\Exceptions\BillingUnavailableException;

final class SimulatedBillingGateway implements BillingGateway
{
    public function purchase(SubscriptionPurchase $purchase): BillingReceipt
    {
        if (app()->isProduction() && config('authentication.demo_mode') !== true) {
            throw new BillingUnavailableException;
        }

        $reference = hash('sha256', $purchase->idempotencyKey);

        return new BillingReceipt(
            provider: 'simulated',
            subscriptionReference: 'sim_sub_'.substr($reference, 0, 24),
            paymentReference: 'sim_pay_'.substr($reference, 24, 24),
            paidAt: now()->toImmutable(),
        );
    }
}
