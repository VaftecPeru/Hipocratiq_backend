<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SmtpOperationalCommandTest extends TestCase
{
    public function test_smtp_test_command_fails_safely_when_configuration_is_incomplete(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => null,
            'mail.mailers.smtp.password' => null,
            'mail.from.address' => null,
        ]);

        $this->artisan('mail:test-smtp')
            ->expectsOutputToContain('SMTP no está configurado completamente.')
            ->assertExitCode(1);
    }

    public function test_smtp_test_command_can_send_through_the_configured_mailer_without_exposing_secrets(): void
    {
        Mail::fake();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.scheme' => 'smtp',
            'mail.mailers.smtp.username' => 'mailer@example.test',
            'mail.mailers.smtp.password' => 'runtime-secret',
            'mail.mailers.smtp.local_domain' => 'hipocratiq.com',
            'mail.mailers.smtp.timeout' => 20,
            'mail.from.address' => 'mailer@example.test',
            'mail.from.name' => 'HIPOCRATIQ',
        ]);

        $this->artisan('mail:test-smtp', ['recipient' => 'qa@example.test'])
            ->expectsOutputToContain('Correo SMTP enviado correctamente')
            ->assertExitCode(0);
    }
}
