<?php

namespace App\Services\Billing;

use App\Billing\BillingReceipt;
use App\Billing\SubscriptionPurchase;
use App\Contracts\BillingGateway;
use App\Exceptions\BillingUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

final class HttpBillingGateway implements BillingGateway
{
    public function purchase(SubscriptionPurchase $purchase): BillingReceipt
    {
        $endpoint = config('billing.http.endpoint');
        $token = config('billing.http.token');

        if (! is_string($endpoint) || $endpoint === '' || ! is_string($token) || $token === '') {
            throw new BillingUnavailableException;
        }

        $response = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('billing.http.timeout_seconds', 20))
            ->withHeaders(['Idempotency-Key' => $purchase->idempotencyKey])
            ->post($endpoint, [
                'amount_cents' => $purchase->amountCents,
                'currency' => $purchase->currency,
                'subject_type' => $purchase->subjectType,
                'plan_key' => $purchase->planKey,
                'billing_cycle' => $purchase->billingCycle,
            ]);

        if (! $response->successful() || $response->json('status') !== 'succeeded') {
            throw new BillingUnavailableException;
        }

        $provider = $response->json('provider');
        $subscriptionReference = $response->json('subscription_reference');
        $paymentReference = $response->json('payment_reference');
        $paidAt = $response->json('paid_at');

        if (! is_string($provider) || ! is_string($subscriptionReference) || ! is_string($paymentReference) || ! is_string($paidAt)) {
            throw new BillingUnavailableException;
        }

        return new BillingReceipt(
            provider: $provider,
            subscriptionReference: $subscriptionReference,
            paymentReference: $paymentReference,
            paidAt: CarbonImmutable::parse($paidAt),
        );
    }
}
