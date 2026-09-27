<?php

namespace App\Providers;

use App\Contracts\BillingGateway;
use App\Contracts\EpidemiologyAnalyzer;
use App\Contracts\RegistrationOtpSender;
use App\Contracts\SmsSender;
use App\Contracts\VideoMeetingProvider;
use App\Contracts\WhatsAppSender;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Models\ClinicMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Auth\EmailRegistrationOtpSender;
use App\Services\Auth\FixedOtpCodeGenerator;
use App\Services\Auth\OtpCodeGenerator;
use App\Services\Auth\RandomOtpCodeGenerator;
use App\Services\Billing\HttpBillingGateway;
use App\Services\Billing\SimulatedBillingGateway;
use App\Services\Billing\UnavailableBillingGateway;
use App\Services\Epidemiology\OpenAiEpidemiologyAnalyzer;
use App\Services\Epidemiology\UnavailableEpidemiologyAnalyzer;
use App\Services\Sms\ArraySmsSender;
use App\Services\Sms\UnavailableSmsSender;
use App\Services\Telemedicine\JitsiVideoMeetingProvider;
use App\Services\Telemedicine\UnavailableVideoMeetingProvider;
use App\Services\WhatsApp\MetaWhatsAppSender;
use App\Services\WhatsApp\UnavailableWhatsAppSender;
use App\Support\Authorization\RolePermissionCatalog;
use App\Support\PhoneNumber;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
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
        $this->app->singleton(UnavailableSmsSender::class);
        $this->app->singleton(
            RegistrationOtpSender::class,
            fn () => $this->app->make(EmailRegistrationOtpSender::class),
        );
        $this->app->singleton(
            SmsSender::class,
            fn () => match (config('sms.driver')) {
                'array' => $this->app->make(ArraySmsSender::class),
                'unavailable' => $this->app->make(UnavailableSmsSender::class),
                default => throw new LogicException('El proveedor SMS configurado no está disponible.'),
            },
        );
        $this->app->singleton(
            BillingGateway::class,
            fn () => match (config('billing.driver')) {
                'http' => $this->app->make(HttpBillingGateway::class),
                'simulated' => $this->app->make(SimulatedBillingGateway::class),
                'unavailable' => $this->app->make(UnavailableBillingGateway::class),
                default => throw new LogicException('El proveedor de facturación configurado no está disponible.'),
            },
        );
        $this->app->singleton(
            EpidemiologyAnalyzer::class,
            fn () => match (config('integrations.ai.provider')) {
                'openai' => $this->app->make(OpenAiEpidemiologyAnalyzer::class),
                'unavailable', null => $this->app->make(UnavailableEpidemiologyAnalyzer::class),
                default => throw new LogicException('El proveedor epidemiológico configurado no está disponible.'),
            },
        );
        $this->app->singleton(
            VideoMeetingProvider::class,
            fn () => match (config('integrations.telemedicine.provider')) {
                'jitsi' => $this->app->make(JitsiVideoMeetingProvider::class),
                'unavailable', null => $this->app->make(UnavailableVideoMeetingProvider::class),
                default => throw new LogicException('El proveedor de telemedicina configurado no está disponible.'),
            },
        );
        $this->app->singleton(
            WhatsAppSender::class,
            fn () => match (config('integrations.whatsapp.driver')) {
                'meta' => $this->app->make(MetaWhatsAppSender::class),
                'unavailable', null => $this->app->make(UnavailableWhatsAppSender::class),
                default => throw new LogicException('El proveedor WhatsApp configurado no está disponible.'),
            },
        );
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        if ($this->app->runningInConsole() === false && config('authentication.demo_mode') !== true) {
            if ($this->app->isProduction() && config('authentication.otp.fixed_code')) {
                throw new LogicException('OTP_FIXED_CODE no puede usarse en producción.');
            }

            if ($this->app->isProduction() && config('billing.driver') === 'simulated') {
                throw new LogicException('BILLING_DRIVER=simulated no puede usarse en producción.');
            }
        }

        Password::defaults(fn () => Password::min(10)->letters()->mixedCase()->numbers());

        Gate::before(fn (User $user, string $ability): ?bool => (
            $user->status === UserStatus::Active && $user->hasRole(RoleKey::SuperAdmin)
        ) ? true : null);

        foreach (RolePermissionCatalog::abilities() as $ability) {
            Gate::define($ability, static function (User $user) use ($ability): bool {
                $membership = request()->attributes->get('clinic_membership');

                if (! $membership instanceof ClinicMembership
                    || (string) $membership->user_id !== (string) $user->getKey()) {
                    return false;
                }

                $roleKey = $membership->role?->key;

                return is_string($roleKey) && RolePermissionCatalog::allows($roleKey, $ability);
            });
        }

        if (config('authentication.demo_mode') === true) {
            $this->configureDemoRateLimiters();

            return;
        }

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

        RateLimiter::for('auth-otp-request', fn (Request $request) => [
            Limit::perMinute(10)->by('otp-request-ip:'.$request->ip()),
            Limit::perHour(5)->by('otp-request-identity:'.$this->authenticationIdentityRateLimitKey($request)),
        ]);

        RateLimiter::for('clinic-registration', fn (Request $request) => [
            Limit::perMinute(8)->by('clinic-registration-ip:'.$request->ip()),
            Limit::perHour(3)->by('clinic-registration-identity:'.$this->authenticationIdentityRateLimitKey($request)),
        ]);

        RateLimiter::for('registration-plan', fn (Request $request) => [
            Limit::perMinute(10)->by('registration-plan-ip:'.$request->ip()),
            Limit::perHour(5)->by('registration-plan-draft:'.$this->registrationDraftRateLimitKey($request)),
        ]);

        RateLimiter::for('clinic-registration-otp', fn (Request $request) => [
            Limit::perMinute(10)->by('clinic-registration-otp-ip:'.$request->ip()),
            Limit::perHour(5)->by('clinic-registration-otp-draft:'.$this->registrationDraftRateLimitKey($request)),
        ]);
    }

    private function configureDemoRateLimiters(): void
    {
        $names = [
            'auth-register',
            'auth-login',
            'auth-otp',
            'auth-otp-request',
            'clinic-registration',
            'registration-plan',
            'clinic-registration-otp',
        ];

        foreach ($names as $name) {
            RateLimiter::for($name, fn (Request $request) => [
                Limit::perMinute(120)->by('demo-'.$name.'-ip:'.$request->ip()),
            ]);
        }
    }

    private function phoneRateLimitKey(Request $request): string
    {
        $identifier = (string) ($request->input('identifier') ?? '');

        if ($identifier !== '') {
            if (str_contains($identifier, '@')) {
                return hash('sha256', strtolower($identifier));
            }

            try {
                return hash('sha256', PhoneNumber::fromE164($identifier)->value);
            } catch (InvalidArgumentException) {
                return hash('sha256', $identifier);
            }
        }

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

    private function authenticationIdentityRateLimitKey(Request $request): string
    {
        $identifier = strtolower((string) ($request->input('identifier')
            ?? $request->input('phone_number')
            ?? $request->input('admin_email')
            ?? $request->input('admin_phone_number')
            ?? $request->input('doctor_email')
            ?? $request->input('doctor_phone_number')));

        if (str_contains($identifier, '@')) {
            return hash('sha256', $identifier);
        }

        try {
            $phoneNumber = $request->filled('country_code')
                ? PhoneNumber::from($identifier, (string) $request->input('country_code'))->value
                : PhoneNumber::fromE164($identifier)->value;
        } catch (InvalidArgumentException) {
            $phoneNumber = $identifier;
        }

        return hash('sha256', $phoneNumber);
    }

    private function registrationDraftRateLimitKey(Request $request): string
    {
        $draftId = $request->cookie('clinic_registration') ?? $request->cookie('doctor_registration');

        return is_string($draftId) && $draftId !== ''
            ? hash('sha256', $draftId)
            : 'ip:'.$request->ip();
    }
}
