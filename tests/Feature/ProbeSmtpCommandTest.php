<?php

namespace Tests\Feature;

use App\Notifications\SmtpProbeNotification;
use App\Services\Mail\MailInfrastructureService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProbeSmtpCommandTest extends TestCase
{
    public function test_probe_command_fails_when_smtp_is_not_configured(): void
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

        $this->artisan('mail:probe', ['recipient' => 'qa@example.test'])
            ->expectsOutputToContain('Prueba de correo fallida:')
            ->assertExitCode(1);
    }

    public function test_probe_command_sends_test_message_when_configured(): void
    {
        Notification::fake();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'runtime-user@example.test',
            'mail.mailers.smtp.password' => 'runtime-secret',
            'mail.from.address' => 'soporte@hipocratiq.com',
            'mail.infrastructure.auth_mode' => 'credentials',
            'mail.infrastructure.probe_recipient' => 'qa@example.test',
        ]);

        $this->artisan('mail:probe')
            ->expectsOutputToContain('Solicitud de envío completada.')
            ->assertSuccessful();

        Notification::assertSentOnDemand(SmtpProbeNotification::class);
    }

    public function test_ses_probe_uses_aws_region_without_smtp_credentials(): void
    {
        Notification::fake();

        config([
            'mail.default' => 'ses',
            'mail.from.address' => 'soporte@hipocratiq.com',
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.infrastructure.probe_recipient' => 'qa@example.test',
            'services.ses.region' => 'us-east-2',
        ]);

        $this->assertTrue(app(MailInfrastructureService::class)->configured());

        $this->artisan('mail:probe')
            ->expectsOutputToContain('Solicitud de envío completada.')
            ->assertSuccessful();

        Notification::assertSentOnDemand(SmtpProbeNotification::class);
    }

    public function test_ses_probe_fails_closed_without_valid_region_or_from(): void
    {
        config([
            'mail.default' => 'ses',
            'mail.from.address' => 'soporte@hipocratiq.com',
            'services.ses.region' => '',
        ]);

        $this->artisan('mail:probe', ['recipient' => 'qa@example.test'])
            ->expectsOutputToContain('Prueba de correo fallida:')
            ->assertExitCode(1);

        config([
            'services.ses.region' => 'us-east-2',
            'mail.from.address' => 'not-an-email',
        ]);

        $this->artisan('mail:probe', ['recipient' => 'qa@example.test'])
            ->expectsOutputToContain('Prueba de correo fallida:')
            ->assertExitCode(1);
    }

    public function test_ses_status_does_not_misreport_smtp_host_as_ses_host(): void
    {
        config([
            'mail.default' => 'ses',
            'mail.from.address' => 'soporte@hipocratiq.com',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'services.ses.region' => 'us-east-2',
        ]);

        $status = app(MailInfrastructureService::class)->status();

        $this->assertSame('ses', $status['driver']);
        $this->assertSame('us-east-2', $status['region']);
        $this->assertNull($status['host']);
        $this->assertNull($status['port']);
        $this->assertStringContainsString('verificación independiente', $status['message']);
    }
}
