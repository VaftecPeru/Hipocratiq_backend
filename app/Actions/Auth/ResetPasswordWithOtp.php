<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ResetPasswordWithOtp
{
    public function __construct(private RecordAuthenticationEvent $recordEvent) {}

    public function handle(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->update(['password' => $password]);
            $user->tokens()->delete();
            $user->authenticationSessions()
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoked_reason' => 'password_reset',
                ]);

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            $this->recordEvent->handle($user, 'password_reset');
        });
    }
}
