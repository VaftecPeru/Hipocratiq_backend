<?php

namespace Tests\Feature;

use App\Notifications\SmtpProbeNotification;
use App\Services\Mail\MailInfrastructureService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MailInfrastructureServiceTest extends TestCase
{
    public function test_credentials_mode_requires_smtp_credentials(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.from.address' => 'soporte@hipocratiq.com',
            'mail.infrastructure.auth_mode' => 'credentials',
        ]);

        $mail = app(MailInfrastructureService::class);
        $this->assertFalse($mail->configured());

        config([
            'mail.mailers.smtp.username' => 'smtp-user@example.test',
            'mail.mailers.smtp.password' => 'runtime-secret',
        ]);

        $this->assertTrue($mail->configured());
    }

    public function test_ip_relay_mode_supports_vps_without_application_credentials(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.mailers.smtp.local_domain' => 'hipocratiq.com',
            'mail.from.address' => 'soporte@hipocratiq.com',
            'mail.infrastructure.auth_mode' => 'ip_relay',
        ]);

        $status = app(MailInfrastructureService::class)->status();

        $this->assertTrue($status['configured']);
        $this->assertSame('ip_relay', $status['auth_mode']);
        $this->assertSame('smtp-relay.gmail.com', $status['host']);
    }

    public function test_smtp_probe_sends_on_demand_notification_and_returns_trace_id(): void
    {
        Notification::fake();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp-relay.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.from.address' => 'soporte@hipocratiq.com',
            'mail.infrastructure.auth_mode' => 'ip_relay',
        ]);

        $traceId = app(MailInfrastructureService::class)->probe('qa@example.test');

        $this->assertNotSame('', $traceId);
        Notification::assertSentOnDemand(SmtpProbeNotification::class);
    }

    public function test_status_never_exposes_smtp_password(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'smtp-user@example.test',
            'mail.mailers.smtp.password' => 'super-sensitive-secret',
            'mail.from.address' => 'soporte@hipocratiq.com',
            'mail.infrastructure.auth_mode' => 'credentials',
        ]);

        $json = json_encode(app(MailInfrastructureService::class)->status(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('super-sensitive-secret', $json);
        $this->assertStringNotContainsString('smtp-user@example.test', $json);
    }
}
