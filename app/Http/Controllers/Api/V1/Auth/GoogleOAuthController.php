<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\StartAuthenticatedSession;
use App\Enums\AuthenticationContext;
use App\Enums\RoleKey;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\GoogleOAuthClient;
use App\Services\Auth\GoogleOAuthStateStore;
use App\Services\Auth\GoogleRoleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GoogleOAuthController extends Controller
{
    public function redirect(
        Request $request,
        GoogleOAuthClient $google,
        GoogleOAuthStateStore $stateStore,
    ): RedirectResponse {
        $data = $request->validate([
            'requested_role' => ['required', 'string', Rule::in([
                RoleKey::SuperAdmin->value,
                RoleKey::ClinicAdmin->value,
                RoleKey::Receptionist->value,
                RoleKey::Doctor->value,
                AuthenticationContext::Patient->value,
            ])],
        ]);

        if (! $google->configured()) {
            throw new RuntimeException('El acceso con Google no está configurado.');
        }

        $state = $stateStore->createAuthorization([
            'requested_role' => $data['requested_role'],
            'created_at' => now()->utc()->toIso8601String(),
        ]);

        return redirect()->away($google->authorizationUrl($state));
    }

    public function callback(
        Request $request,
        GoogleOAuthClient $google,
        GoogleOAuthStateStore $stateStore,
    ): RedirectResponse {
        $frontend = $google->frontendCallbackUrl();

        if ($request->filled('error')) {
            return redirect()->away($frontend.'?error=google_access_denied');
        }

        $state = $request->query('state');
        $code = $request->query('code');

        if (! is_string($state) || ! is_string($code) || $state === '' || $code === '') {
            return redirect()->away($frontend.'?error=google_callback_invalid');
        }

        try {
            $authorization = $stateStore->consumeAuthorization($state);
            $identity = $google->userFromCode($code);
            $handoff = $stateStore->createHandoff([
                'requested_role' => $authorization['requested_role'],
                'identity' => $identity,
            ]);

            return redirect()->away($frontend.'?handoff='.rawurlencode($handoff));
        } catch (Throwable) {
            return redirect()->away($frontend.'?error=google_callback_failed');
        }
    }

    public function complete(
        Request $request,
        GoogleOAuthStateStore $stateStore,
        GoogleRoleAccess $roleAccess,
        StartAuthenticatedSession $startSession,
    ): JsonResponse {
        $data = $request->validate([
            'handoff' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

        $payload = $stateStore->consumeHandoff($data['handoff']);
        $identity = $payload['identity'] ?? null;
        $requestedRole = $payload['requested_role'] ?? null;

        if (! is_array($identity) || ! is_string($requestedRole)) {
            throw ValidationException::withMessages(['google' => ['La identidad de Google no es válida.']]);
        }

        $googleSub = $identity['sub'] ?? null;
        $email = $identity['email'] ?? null;

        if (! is_string($googleSub) || ! is_string($email)) {
            throw ValidationException::withMessages(['google' => ['Google no devolvió una identidad válida.']]);
        }

        $user = User::query()
            ->where('google_sub', $googleSub)
            ->orWhere('email', strtolower($email))
            ->first();

        if ($user === null) {
            $registrationType = $this->registrationType($requestedRole);

            if ($registrationType === null) {
                throw ValidationException::withMessages([
                    'google' => ['Este rol requiere una cuenta previamente creada o una invitación activa.'],
                ]);
            }

            $registrationToken = $stateStore->createRegistration([
                'registration_type' => $registrationType,
                'requested_role' => $requestedRole,
                'identity' => $identity,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'registration_required',
                    'registration_type' => $registrationType,
                    'registration_token' => $registrationToken,
                    'identity' => [
                        'name' => $identity['name'],
                        'email' => $identity['email'],
                        'avatar' => $identity['avatar'] ?? null,
                    ],
                ],
            ]);
        }

        if ($user->google_sub !== null && ! hash_equals($user->google_sub, $googleSub)) {
            throw ValidationException::withMessages([
                'google' => ['El correo ya está vinculado a otra identidad de Google.'],
            ]);
        }

        $context = $roleAccess->context($user, $requestedRole);

        DB::transaction(function () use ($user, $identity, $googleSub): void {
            $user->forceFill([
                'google_sub' => $googleSub,
                'google_avatar_url' => $identity['avatar'] ?? null,
                'google_linked_at' => $user->google_linked_at ?? now(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        });

        $startSession->handle($request, $user->fresh(), $context);

        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'authenticated',
                'user' => new UserResource($user->fresh()),
                'actor_context' => $context->value,
            ],
        ]);
    }

    public function registrationProfile(
        Request $request,
        GoogleOAuthStateStore $stateStore,
    ): JsonResponse {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/'],
            'type' => ['required', Rule::in(['clinic', 'doctor', 'patient'])],
        ]);
        $payload = $stateStore->registration($data['token'], $data['type']);
        $identity = $payload['identity'] ?? null;

        if (! is_array($identity)) {
            throw ValidationException::withMessages(['google' => ['La identidad de Google no es válida.']]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'registration_type' => $payload['registration_type'],
                'identity' => [
                    'name' => $identity['name'],
                    'email' => $identity['email'],
                    'avatar' => $identity['avatar'] ?? null,
                ],
            ],
        ]);
    }

    private function registrationType(string $requestedRole): ?string
    {
        return match ($requestedRole) {
            RoleKey::ClinicAdmin->value => 'clinic',
            RoleKey::Doctor->value => 'doctor',
            AuthenticationContext::Patient->value => 'patient',
            default => null,
        };
    }
}
