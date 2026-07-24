<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RegistrationRateLimitTest extends TestCase
{
    public function test_plan_selection_uses_an_independent_bucket_per_registration_draft(): void
    {
        $limiter = RateLimiter::limiter('registration-plan');
        $keys = [];

        foreach (range(1, 4) as $draft) {
            $request = Request::create('/api/v1/auth/register/clinic/plan', 'POST');
            $request->cookies->set('clinic_registration', 'draft-'.$draft);
            $keys[] = $limiter($request)[1]->key;
        }

        $this->assertCount(4, array_unique($keys));
    }

    public function test_plan_selection_without_a_draft_falls_back_to_the_request_ip(): void
    {
        $limiter = RateLimiter::limiter('registration-plan');
        $firstRequest = Request::create('/api/v1/auth/register/clinic/plan', 'POST', server: [
            'REMOTE_ADDR' => '192.0.2.10',
        ]);
        $secondRequest = Request::create('/api/v1/auth/register/clinic/plan', 'POST', server: [
            'REMOTE_ADDR' => '192.0.2.11',
        ]);

        $this->assertNotSame(
            $limiter($firstRequest)[1]->key,
            $limiter($secondRequest)[1]->key,
        );
    }
}
