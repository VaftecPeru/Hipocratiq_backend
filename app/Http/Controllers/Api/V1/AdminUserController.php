<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminUserIndexRequest;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class AdminUserController extends Controller
{
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
            'data' => $users->getCollection()->map(fn (User $user): array => $this->serializeUser($user)),
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
                'clinics' => Clinic::query()->orderBy('name')->get(['id', 'name']),
            ],
        ]);
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
                'doctorProfile:id,user_id,clinic_id',
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
            RoleKey::Doctor->value => [RoleKey::SuperAdmin->value, RoleKey::ClinicAdmin->value],
            'patient' => [RoleKey::SuperAdmin->value, RoleKey::ClinicAdmin->value, RoleKey::Doctor->value],
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
            'clinic' => $clinic?->only(['id', 'name', 'address']),
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
        foreach ([RoleKey::SuperAdmin, RoleKey::ClinicAdmin, RoleKey::Doctor] as $role) {
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
                'doctors' => $roleCounts->get(RoleKey::Doctor->value, 0),
                'patients' => $roleCounts->get('patient', 0),
            ],
            'role_distribution' => $roleCounts->map(fn (int $count, string $role): array => [
                'role' => $role,
                'count' => $count,
                'percentage' => $totalUsers === 0 ? 0 : round(($count / $totalUsers) * 100, 1),
            ])->values(),
            'country_distribution' => $countryCounts->map(fn ($count, string $countryCode): array => [
                'country_code' => $countryCode,
                'count' => (int) $count,
                'percentage' => $totalUsers === 0 ? 0 : round((((int) $count) / $totalUsers) * 100, 1),
            ])->values(),
            'recent_activity' => $this->adminUserQuery()
                ->limit(5)
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'title' => 'Nuevo usuario',
                    'detail' => $user->name,
                    'role' => $this->effectiveRole($user->roles->pluck('key'), $user->patientProfile !== null),
                    'occurred_at' => $user->created_at?->toIso8601String(),
                ]),
        ];
    }
}
