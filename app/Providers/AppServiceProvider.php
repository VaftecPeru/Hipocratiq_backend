<?php

namespace App\Providers;

use App\Contracts\SmsSender;
use App\Services\Auth\FixedOtpCodeGenerator;
use App\Services\Auth\OtpCodeGenerator;
use App\Services\Auth\RandomOtpCodeGenerator;
use App\Services\Sms\ArraySmsSender;
use App\Support\PhoneNumber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OtpCodeGenerator::class, function (): OtpCodeGenerator {
            $fixedCode = config('authentication.otp.fixed_code');

            return is_string($fixedCode) && $fixedCode !== ''
                ? new FixedOtpCodeGenerator($fixedCode)
                : new RandomOtpCodeGenerator;
        });
        $this->app->singleton(ArraySmsSender::class);
        $this->app->singleton(
            SmsSender::class,
            fn () => match (config('sms.driver')) {
                'array' => $this->app->make(ArraySmsSender::class),
                default => throw new LogicException('El proveedor SMS configurado no está disponible.'),
            },
        );
    }

    public function boot(): void
    {
        if ($this->app->isProduction() && config('sms.driver') === 'array') {
            throw new LogicException('Configura un proveedor SMS antes de ejecutar en producción.');
        }

        if ($this->app->isProduction() && config('authentication.otp.fixed_code')) {
            throw new LogicException('OTP_FIXED_CODE no puede usarse en producción.');
        }

        Password::defaults(fn () => Password::min(10)->letters()->mixedCase()->numbers());

        RateLimiter::for('auth-register', fn (Request $request) => [
            Limit::perMinute(10)->by('register-ip:'.$request->ip()),
            Limit::perHour(5)->by('register-phone:'.$this->phoneRateLimitKey($request)),
        ]);

        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(10)->by('login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('login-phone:'.$this->phoneRateLimitKey($request)),
        ]);

        RateLimiter::for('auth-otp', fn (Request $request) => [
            Limit::perMinute(10)->by('otp-ip:'.$request->ip()),
            Limit::perMinute(5)->by('otp-challenge:'.hash('sha256', (string) $request->input('challenge_id'))),
        ]);
    }

    private function phoneRateLimitKey(Request $request): string
    {
        try {
            $phoneNumber = PhoneNumber::from(
                (string) $request->input('phone_number'),
                (string) $request->input('country_code'),
            )->value;
        } catch (InvalidArgumentException) {
            $phoneNumber = strtoupper((string) $request->input('country_code')).'|'.(string) $request->input('phone_number');
        }

        return hash('sha256', $phoneNumber);
    }
}
