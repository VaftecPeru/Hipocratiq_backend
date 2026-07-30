<?php

namespace App\Contracts;

use App\Billing\BillingReceipt;
use App\Billing\SubscriptionPurchase;

interface BillingGateway
{
    public function purchase(SubscriptionPurchase $purchase): BillingReceipt;
}
