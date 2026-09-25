<?php

namespace Tests\Feature\Auth;

use App\Contracts\RegistrationOtpSender;
use App\Notifications\OtpCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationEmailOtpSenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_otp_sender_routes_code_to_the_requested_email(): void
    {
        Notification::fake();

        $this->app->make(RegistrationOtpSender::class)->send(
            'admin@clinic.example',
            'Administradora Clínica',
            '123456',
        );

        Notification::assertSentOnDemand(
            OtpCodeNotification::class,
            function (OtpCodeNotification $notification, array $channels, object $notifiable): bool {
                return in_array('mail', $channels, true)
                    && $notifiable->routeNotificationFor('mail') === 'admin@clinic.example';
            },
        );
    }
}
