<?php

namespace App\Actions\Auth;

use App\Enums\AuthenticationContext;
use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

final class StartMobileSession
{
    public function __construct(private RecordAuthenticationEvent $recordEvent) {}

    public function handle(
        User $user,
        AuthenticationContext $context,
        string $deviceName,
        string $deviceIdHash,
    ): NewAccessToken {
        $tokenName = sprintf('mobile:%s:%s', substr($deviceIdHash, 0, 16), $deviceName);
        $user->tokens()->where('name', $tokenName)->delete();
        $expiresAt = now()->addMinutes(config('authentication.mobile_token.ttl_minutes'));
        $token = $user->createToken($tokenName, [$context->value], $expiresAt);

        $user->update(['last_login_at' => now()]);
        $this->recordEvent->handle($user, 'mobile_login_succeeded', metadata: [
            'actor_context' => $context->value,
            'device_name' => $deviceName,
        ]);

        return $token;
    }
}
