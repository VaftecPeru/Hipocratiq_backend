<?php

namespace Tests\Unit\Mail;

use App\Services\Mail\SmtpConfigurationInspector;
use Tests\TestCase;

class SmtpConfigurationInspectorTest extends TestCase
{
    public function test_it_reports_smtp_ready_only_when_required_configuration_is_complete(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.scheme' => 'smtp',
            'mail.mailers.smtp.username' => 'mailer@example.test',
            'mail.mailers.smtp.password' => 'app-password',
            'mail.mailers.smtp.local_domain' => 'hipocratiq.com',
            'mail.mailers.smtp.timeout' => 20,
            'mail.from.address' => 'mailer@example.test',
        ]);

        $status = (new SmtpConfigurationInspector)->status();

        $this->assertTrue($status['configured']);
        $this->assertSame('smtp.gmail.com', $status['host']);
        $this->assertSame(587, $status['port']);
        $this->assertSame(20, $status['timeout_seconds']);
    }

    public function test_it_rejects_incomplete_or_non_smtp_mail_configuration(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'mailer@example.test',
            'mail.mailers.smtp.password' => null,
            'mail.from.address' => 'mailer@example.test',
        ]);

        $this->assertFalse((new SmtpConfigurationInspector)->configured());

        config(['mail.default' => 'log']);

        $this->assertFalse((new SmtpConfigurationInspector)->configured());
    }
}
