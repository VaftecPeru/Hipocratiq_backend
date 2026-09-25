<?php

namespace Tests\Feature;

use App\Notifications\SmtpProbeNotification;
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
            ->expectsOutputToContain('Prueba SMTP fallida:')
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
            ->expectsOutputToContain('SMTP operativo.')
            ->assertSuccessful();

        Notification::assertSentOnDemand(SmtpProbeNotification::class);
    }
}
