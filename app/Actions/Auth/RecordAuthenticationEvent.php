<?php

namespace App\Actions\Auth;

use App\Models\AuthenticationEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

final class RecordAuthenticationEvent
{
    public function __construct(private Request $request) {}

    public function handle(
        ?User $user,
        string $event,
        string $outcome = 'success',
        array $metadata = [],
    ): AuthenticationEvent {
        $requestId = $this->request->header('X-Request-ID');

        return AuthenticationEvent::query()->create([
            'user_id' => $user?->id,
            'event' => $event,
            'outcome' => $outcome,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'request_id' => is_string($requestId) && preg_match('/\A[A-Za-z0-9._-]{1,100}\z/', $requestId) === 1
                ? $requestId
                : null,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    public function handleSafely(
        ?User $user,
        string $event,
        string $outcome = 'success',
        array $metadata = [],
    ): void {
        try {
            $this->handle($user, $event, $outcome, $metadata);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
