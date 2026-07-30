<?php

namespace App\Services\Billing;

use App\Billing\BillingReceipt;
use App\Billing\SubscriptionPurchase;
use App\Contracts\BillingGateway;
use App\Exceptions\BillingUnavailableException;

final class UnavailableBillingGateway implements BillingGateway
{
    public function purchase(SubscriptionPurchase $purchase): BillingReceipt
    {
        throw new BillingUnavailableException;
    }
}
