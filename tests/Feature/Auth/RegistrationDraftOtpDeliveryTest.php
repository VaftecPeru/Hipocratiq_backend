<?php

namespace Tests\Feature\Auth;

use App\Actions\Auth\RegisterPatient;
use App\Contracts\SmsSender;
use App\Enums\OtpDeliveryStatus;
use App\Models\Clinic;
use App\Services\Auth\ClinicRegistrationDraftStore;
use App\Services\Auth\DoctorRegistrationDraftStore;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class RegistrationDraftOtpDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_patient_registration_sends_sms_after_its_database_transaction_commits(): void
    {
        $sender = new class implements SmsSender
        {
            /** @var list<int> */
            public array $transactionLevels = [];

            public function send(string $phoneNumber, string $message): void
            {
                $this->transactionLevels[] = DB::transactionLevel();
            }
        };
        $this->app->instance(SmsSender::class, $sender);
        $clinic = Clinic::factory()->create();
        $testTransactionLevel = DB::transactionLevel();

        $challenge = $this->app->make(RegisterPatient::class)->handle([
            'name' => 'Ana Torres',
            'phone_number' => '55 1234 5678',
            'country_code' => 'MX',
            'clinic_id' => $clinic->id,
        ]);

        $this->assertSame([$testTransactionLevel], $sender->transactionLevels);
        $this->assertSame(OtpDeliveryStatus::Sent, $challenge->delivery_status);
    }

    public function test_clinic_draft_releases_its_cache_lock_during_delivery_and_restores_previous_otp_on_failure(): void
    {
        $sender = $this->lockAwareSender();
        $this->app->instance(SmsSender::class, $sender);
        $store = $this->app->make(ClinicRegistrationDraftStore::class);
        $token = $store->create([
            'admin_phone_number' => '+525512345678',
        ]);
        $store->update($token, ['plan' => ['key' => 'basic']]);
        $sender->lockKey = 'clinic-registration:'.hash('sha256', $token).':lock';

        $firstChallenge = $store->issueOtp($token);
        $this->travel(config('authentication.otp.resend_seconds') + 1)->seconds();
        $sender->shouldFail = true;

        try {
            $store->issueOtp($token, true);
            $this->fail('The failed SMS delivery was accepted.');
        } catch (ServiceUnavailableHttpException) {
            // Expected.
        }

        $draft = $store->get($token);

        $this->assertSame([true, true], $sender->lockAcquired);
        $this->assertSame($firstChallenge['challenge_id'], $draft['otp']['challenge_id']);
        $this->assertSame('sent', $draft['otp']['delivery_status']);
        $this->assertSame(0, $draft['resend_count']);
    }

    public function test_doctor_draft_releases_its_cache_lock_during_delivery(): void
    {
        $sender = $this->lockAwareSender();
        $this->app->instance(SmsSender::class, $sender);
        $store = $this->app->make(DoctorRegistrationDraftStore::class);
        $token = $store->create([
            'practice_type' => 'independent',
            'doctor_phone_number' => '+525587654321',
        ]);
        $store->selectPlan($token, ['key' => 'basic']);
        $sender->lockKey = 'doctor-registration:'.hash('sha256', $token).':lock';

        $store->issueOtp($token);

        $this->assertSame([true], $sender->lockAcquired);
    }

    private function lockAwareSender(): object
    {
        return new class implements SmsSender
        {
            public string $lockKey = '';

            /** @var list<bool> */
            public array $lockAcquired = [];

            public bool $shouldFail = false;

            public function send(string $phoneNumber, string $message): void
            {
                $lock = Cache::lock($this->lockKey, 10);
                $acquired = $lock->get();
                $this->lockAcquired[] = $acquired;

                if ($acquired) {
                    $lock->release();
                }

                if ($this->shouldFail) {
                    throw new RuntimeException('Provider unavailable.');
                }
            }
        };
    }
}
