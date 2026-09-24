<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\EnsureSuperAdminRemains;
use App\Actions\Admin\RecordAdminAuditEvent;
use App\Actions\Admin\UpdateAdminUserAccess;
use App\Enums\AdminAssignableRole;
use App\Enums\OnboardingStatus;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminUserIndexRequest;
use App\Http\Requests\Api\V1\AdminUserMessageRequest;
use App\Http\Requests\Api\V1\AdminUserStoreRequest;
use App\Http\Requests\Api\V1\AdminUserUpdateRequest;
use App\Models\Clinic;
use App\Models\User;
use App\Notifications\AdminUserMessageNotification;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AdminUserController extends Controller
{
    public function store(
        AdminUserStoreRequest $request,
        UpdateAdminUserAccess $updateAccess,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $data = $request->validated();

        try {
            $phone = PhoneNumber::from($data['phone_number'], $data['country_code']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['phone_number' => [$exception->getMessage()]]);
        }

        $email = isset($data['email']) ? mb_strtolower(trim($data['email'])) : null;
        if (User::query()->where('phone_number', $phone->value)->when(
            $email !== null,
            fn (Builder $query) => $query->orWhere('email', $email),
        )->exists()) {
            throw ValidationException::withMessages([
                'phone_number' => ['El correo o teléfono ya pertenece a otro usuario.'],
            ]);
        }

        $clinic = isset($data['clinic_id']) ? Clinic::query()->findOrFail($data['clinic_id']) : null;
        $role = AdminAssignableRole::from($data['role']);
        $user = DB::transaction(function () use ($request, $data, $phone, $email, $clinic, $role, $updateAccess): User {
            $user = User::query()->create([
                'name' => trim($data['name']),
                'email' => $email,
                'phone_number' => $phone->value,
                'country_code' => $phone->countryCode,
                'password' => Str::password(48),
                'status' => UserStatus::Active,
                'onboarding_status' => OnboardingStatus::Completed,
                'phone_verified_at' => now(),
            ]);

            return $updateAccess->handle($request->user(), $user, $role, $clinic)['user'];
        });

        $audit->handle(
            $request->user(),
            $request,
            'users',
            'user_created',
            User::class,
            $user->id,
            $user->name,
            clinic: $clinic,
            countryCode: $user->country_code,
            metadata: [
                'role' => $role->value,
                'clinic_id' => $clinic?->id,
                'requires_password_reset' => true,
            ],
            critical: true,
        );

        $user->load(['roles:id,key,name', 'clinicMemberships.clinic:id,name,address', 'doctorProfile.clinic:id,name,address', 'patientProfile.clinic:id,name,address']);

        return response()->json([
            'success' => true,
            'data' => $this->serializeUser($user),
            'meta' => ['requires_password_reset' => true],
        ], 201);
    }

    public function index(AdminUserIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $usersQuery = $this->adminUserQuery()
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this->applySearch($query, $search))
            ->when($filters['country_code'] ?? null, fn (Builder $query, string $countryCode) => $query->where('country_code', $countryCode))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['clinic_id'] ?? null, fn (Builder $query, int $clinicId) => $this->applyClinicFilter($query, $clinicId))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $this->applyRoleFilter($query, $role));
        $users = $usersQuery->paginate($filters['per_page'] ?? 10);

        return response()->json([
            'success' => true,
            'data' => $users->getCollection()->map(fn (User $user): array => $this->serializeUser($user))->values()->all(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'from' => $users->firstItem(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'to' => $users->lastItem(),
                'total' => $users->total(),
            ],
            'summary' => $this->summary(),
            'filter_options' => [
                'clinics' => Clinic::query()->orderBy('name')->get(['id', 'name', 'country_code', 'status'])->values()->all(),
            ],
        ]);
    }

    public function update(
        AdminUserUpdateRequest $request,
        User $user,
        RecordAdminAuditEvent $audit,
        EnsureSuperAdminRemains $ensureSuperAdminRemains,
    ): JsonResponse {
        $actor = $request->user();
        $data = $request->validated();

        if ($actor?->is($user) && isset($data['status']) && $data['status'] !== UserStatus::Active->value) {
            throw ValidationException::withMessages(['status' => ['No puedes suspender o deshabilitar tu propia cuenta.']]);
        }

        [$user, $before] = DB::transaction(function () use ($user, $data, $ensureSuperAdminRemains): array {
            $mayDeactivate = isset($data['status']) && $data['status'] !== UserStatus::Active->value;

            if ($mayDeactivate) {
                $ensureSuperAdminRemains->lockSentinel();
            }

            $lockedUser = User::query()
                ->with('roles:id,key')
                ->lockForUpdate()
                ->findOrFail($user->getKey());

            $before = $lockedUser->only(['name', 'email', 'phone_number', 'country_code', 'status']);

            if (array_key_exists('email', $data) && $data['email'] !== null) {
                $data['email'] = mb_strtolower(trim($data['email']));
            }

            if ($mayDeactivate
                && $lockedUser->status === UserStatus::Active
            ) {
                $ensureSuperAdminRemains->handle($lockedUser, 'status');
            }

            $lockedUser->fill($data)->save();

            if (isset($data['status']) && $data['status'] !== UserStatus::Active->value) {
                $lockedUser->authenticationSessions()->delete();
                $lockedUser->tokens()->delete();
            }

            return [$lockedUser, $before];
        });
        $user->load(['roles:id,key,name', 'clinicMemberships.clinic:id,name,address', 'doctorProfile.clinic:id,name,address', 'patientProfile.clinic:id,name,address']);
        $audit->handle(
            $actor,
            $request,
            'users',
            'user_updated',
            User::class,
            $user->id,
            $user->name,
            countryCode: $user->country_code,
            metadata: ['before' => $before, 'after' => $user->only(array_keys($before))],
            critical: isset($data['status']),
        );

        return response()->json(['success' => true, 'data' => $this->serializeUser($user)]);
    }

    public function message(
        AdminUserMessageRequest $request,
        User $user,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        if ($user->email === null) {
            throw ValidationException::withMessages([
                'email' => ['Este usuario no tiene un correo registrado para recibir mensajes.'],
            ]);
        }

        $actor = $request->user();
        $data = $request->validated();
        $notification = new AdminUserMessageNotification($data['subject'], $data['body'], $actor);

        $user->notify($notification);
        if (($data['send_copy'] ?? false) && $actor->email !== null && ! $actor->is($user)) {
            $actor->notify($notification);
        }

        $audit->handle(
            $actor,
            $request,
            'users',
            'user_message_sent',
            User::class,
            $user->id,
            $user->name,
            countryCode: $user->country_code,
            metadata: ['subject' => $data['subject'], 'copy_sent' => (bool) ($data['send_copy'] ?? false)],
        );

        return response()->json([
            'success' => true,
            'message' => 'Mensaje programado para envío.',
        ], 202);
    }

    private function adminUserQuery(): Builder
    {
        return User::query()
            ->select(['id', 'name', 'email', 'phone_number', 'country_code', 'status', 'last_login_at', 'created_at'])
            ->with([
                'roles:id,key,name',
                'clinicMemberships' => fn ($query) => $query
                    ->select(['id', 'user_id', 'clinic_id', 'status'])
                    ->where('status', 'active')
                    ->with('clinic:id,name,address'),
                'doctorProfile:id,user_id,clinic_id,medical_license,medical_license_country_code,medical_license_issuer,medical_license_verification_status,medical_license_verified_at,medical_license_verification_reference,specialty_license,specialty_license_verification_status,specialty_license_verified_at,specialty_license_verification_reference',
                'doctorProfile.clinic:id,name,address',
                'patientProfile:id,user_id,clinic_id',
                'patientProfile.clinic:id,name,address',
            ])
            ->orderByDesc('id');
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        return $query->where(function (Builder $query) use ($search): void {
            $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone_number', 'like', "%{$search}%")
                ->orWhereHas('clinicMemberships.clinic', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
                ->orWhereHas('doctorProfile.clinic', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
                ->orWhereHas('patientProfile.clinic', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"));
        });
    }

    private function applyClinicFilter(Builder $query, int $clinicId): Builder
    {
        return $query->where(function (Builder $query) use ($clinicId): void {
            $query
                ->whereHas('clinicMemberships', fn (Builder $query) => $query->where('clinic_id', $clinicId))
                ->orWhereHas('doctorProfile', fn (Builder $query) => $query->where('clinic_id', $clinicId))
                ->orWhereHas('patientProfile', fn (Builder $query) => $query->where('clinic_id', $clinicId));
        });
    }

    private function applyRoleFilter(Builder $query, string $role): Builder
    {
        $higherPriorityRoles = match ($role) {
            RoleKey::ClinicAdmin->value => [RoleKey::SuperAdmin->value],
            RoleKey::Receptionist->value => [RoleKey::SuperAdmin->value, RoleKey::ClinicAdmin->value],
            RoleKey::Doctor->value => [RoleKey::SuperAdmin->value, RoleKey::ClinicAdmin->value],
            'patient' => [RoleKey::SuperAdmin->value, RoleKey::ClinicAdmin->value, RoleKey::Receptionist->value, RoleKey::Doctor->value],
            default => [],
        };

        if ($role === 'patient') {
            return $query
                ->whereHas('patientProfile')
                ->when($higherPriorityRoles !== [], fn (Builder $query) => $query->whereDoesntHave('roles', fn (Builder $query) => $query->whereIn('key', $higherPriorityRoles)));
        }

        if ($role === 'unassigned') {
            return $query->whereDoesntHave('roles')->whereDoesntHave('patientProfile');
        }

        return $query
            ->whereHas('roles', fn (Builder $query) => $query->where('key', $role))
            ->when($higherPriorityRoles !== [], fn (Builder $query) => $query->whereDoesntHave('roles', fn (Builder $query) => $query->whereIn('key', $higherPriorityRoles)));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        $roleKeys = $user->roles->pluck('key');
        $clinic = $user->clinicMemberships->first()?->clinic
            ?? $user->doctorProfile?->clinic
            ?? $user->patientProfile?->clinic;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'country_code' => $user->country_code,
            'role' => $this->effectiveRole($roleKeys, $user->patientProfile !== null),
            'assignable_roles' => collect([
                AdminAssignableRole::SuperAdmin->value,
                AdminAssignableRole::ClinicAdmin->value,
                AdminAssignableRole::Receptionist->value,
                $user->doctorProfile !== null ? AdminAssignableRole::Doctor->value : null,
                $user->patientProfile !== null ? AdminAssignableRole::Patient->value : null,
            ])->filter()->values()->all(),
            'clinic' => $clinic?->only(['id', 'name', 'address']),
            'professional_credential' => $user->doctorProfile === null ? null : [
                'doctor_profile_id' => $user->doctorProfile->id,
                'medical_license' => $user->doctorProfile->medical_license,
                'country_code' => $user->doctorProfile->medical_license_country_code,
                'issuer' => $user->doctorProfile->medical_license_issuer,
                'verification_status' => $user->doctorProfile->medical_license_verification_status,
                'verified_at' => $user->doctorProfile->medical_license_verified_at?->toIso8601String(),
                'verification_reference' => $user->doctorProfile->medical_license_verification_reference,
                'specialty_license' => $user->doctorProfile->specialty_license,
                'specialty_verification_status' => $user->doctorProfile->specialty_license_verification_status,
                'specialty_verified_at' => $user->doctorProfile->specialty_license_verified_at?->toIso8601String(),
                'specialty_verification_reference' => $user->doctorProfile->specialty_license_verification_reference,
            ],
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'status' => $user->status->value,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, string>  $roleKeys
     */
    private function effectiveRole(Collection $roleKeys, bool $isPatient): string
    {
        foreach ([RoleKey::SuperAdmin, RoleKey::ClinicAdmin, RoleKey::Receptionist, RoleKey::Doctor] as $role) {
            if ($roleKeys->contains($role->value)) {
                return $role->value;
            }
        }

        return $isPatient ? 'patient' : 'unassigned';
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        $totalUsers = User::query()->count();
        $roleCounts = Collection::make([
            RoleKey::SuperAdmin->value,
            RoleKey::ClinicAdmin->value,
            RoleKey::Receptionist->value,
            RoleKey::Doctor->value,
            'patient',
            'unassigned',
        ])->mapWithKeys(function (string $role): array {
            $query = User::query();
            $this->applyRoleFilter($query, $role);

            return [$role => $query->count()];
        });
        $countryCounts = User::query()
            ->selectRaw('country_code, COUNT(*) as aggregate')
            ->groupBy('country_code')
            ->pluck('aggregate', 'country_code');

        return [
            'totals' => [
                'users' => $totalUsers,
                'active_users' => User::query()->where('status', UserStatus::Active->value)->count(),
                'administrators' => $roleCounts->get(RoleKey::SuperAdmin->value, 0) + $roleCounts->get(RoleKey::ClinicAdmin->value, 0),
                'receptionists' => $roleCounts->get(RoleKey::Receptionist->value, 0),
                'doctors' => $roleCounts->get(RoleKey::Doctor->value, 0),
                'patients' => $roleCounts->get('patient', 0),
            ],
            'role_distribution' => $roleCounts->map(fn (int $count, string $role): array => [
                'role' => $role,
                'count' => $count,
                'percentage' => $totalUsers === 0 ? 0 : round(($count / $totalUsers) * 100, 1),
            ])->values()->all(),
            'country_distribution' => $countryCounts->map(fn ($count, string $countryCode): array => [
                'country_code' => $countryCode,
                'count' => (int) $count,
                'percentage' => $totalUsers === 0 ? 0 : round((((int) $count) / $totalUsers) * 100, 1),
            ])->values()->all(),
            'recent_activity' => $this->adminUserQuery()
                ->limit(5)
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'title' => 'Nuevo usuario',
                    'detail' => $user->name,
                    'role' => $this->effectiveRole($user->roles->pluck('key'), $user->patientProfile !== null),
                    'occurred_at' => $user->created_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }
}
