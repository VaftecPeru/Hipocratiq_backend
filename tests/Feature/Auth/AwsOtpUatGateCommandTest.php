<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class AwsOtpUatGateCommandTest extends TestCase
{
    public function test_uat_gate_refuses_production_before_contacting_aws(): void
    {
        config()->set('app.env', 'production');

        $this->artisan('otp:aws-uat-gate', [
            '--phone' => '+51900112844',
            '--database' => 'hipocratiq_b109_uat',
        ])
            ->expectsOutputToContain('Entorno no permitido [production]')
            ->assertExitCode(1);
    }

    public function test_uat_gate_refuses_an_unexpected_database_before_contacting_aws(): void
    {
        config()->set('app.env', 'testing');

        $this->artisan('otp:aws-uat-gate', [
            '--phone' => '+51900112844',
            '--database' => 'database-that-must-not-match',
        ])
            ->expectsOutputToContain('Base de datos insegura')
            ->assertExitCode(1);
    }
}
