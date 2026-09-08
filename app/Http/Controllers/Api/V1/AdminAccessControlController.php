<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Actions\Admin\UpdateAdminUserAccess;
use App\Enums\AdminAssignableRole;
use App\Enums\ClinicStatus;
use App\Enums\RoleKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminUserAccessUpdateRequest;
use App\Models\AdminAuditEvent;
use App\Models\Clinic;
use App\Models\User;
use App\Support\Authorization\RolePermissionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAccessControlController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roles = collect(RolePermissionCatalog::roles())
            ->map(fn (array $role): array => $role + [
                'user_count' => $this->roleQuery(AdminAssignableRole::from($role['key']))->count(),
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'actor_user_id' => $request->user()->getKey(),
                'enforcement' => 'catalog_gate',
                'roles' => $roles,
                'permissions' => RolePermissionCatalog::permissions(),
                'clinics' => Clinic::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'country_code', 'status'])
                    ->map(fn (Clinic $clinic): array => [
                        'id' => $clinic->id,
                        'name' => $clinic->name,
                        'country_code' => $clinic->country_code,
                        'status' => $clinic->status->value,
                    ])->values()->all(),
                'summary' => [
                    'total_users' => User::query()->count(),
                    'active_clinics' => Clinic::query()->where('status', ClinicStatus::Active->value)->count(),
                    'unassigned_users' => User::query()->whereDoesntHave('roles')->whereDoesntHave('patientProfile')->count(),
                ],
                'recent_changes' => AdminAuditEvent::query()
                    ->with('actor:id,name')
                    ->where('module', 'access_control')
                    ->latest('occurred_at')
                    ->limit(8)
                    ->get()
                    ->map(fn (AdminAuditEvent $event): array => [
                        'id' => $event->id,
                        'actor_name' => $event->actor?->name ?? 'Sistema',
                        'resource_id' => $event->resource_id,
                        'resource_label' => $event->resource_label,
                        'action' => $event->action,
                        'metadata' => $event->metadata,
                        'occurred_at' => $event->occurred_at?->toIso8601String(),
                    ])->values()->all(),
            ],
        ]);
    }

    public function update(
        AdminUserAccessUpdateRequest $request,
        User $user,
        UpdateAdminUserAccess $updateAccess,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $role = AdminAssignableRole::from($request->validated('role'));
        $clinicId = $request->validated('clinic_id');
        $clinic = $clinicId === null ? null : Clinic::query()->findOrFail($clinicId);
        $result = $updateAccess->handle($request->user(), $user, $role, $clinic);
        $updatedUser = $result['user'];

        $audit->handle(
            $request->user(),
            $request,
            'access_control',
            'user_access_updated',
            User::class,
            $updatedUser->getKey(),
            $updatedUser->name,
            clinic: $clinic,
            countryCode: $clinic?->country_code ?? $updatedUser->country_code,
            metadata: [
                'before' => $result['before'],
                'after' => $result['after'],
                'changed' => $result['changed'],
                'sessions_revoked' => $result['changed'],
            ],
            critical: true,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'user_id' => $updatedUser->id,
                'role' => $result['after']['role'],
                'clinic' => $clinic?->only(['id', 'name', 'address']),
                'changed' => $result['changed'],
                'sessions_revoked' => $result['changed'],
            ],
        ]);
    }

    private function roleQuery(AdminAssignableRole $role): Builder
    {
        $query = User::query();

        if ($role === AdminAssignableRole::Patient) {
            return $query
                ->whereHas('patientProfile')
                ->whereDoesntHave('roles', fn (Builder $query) => $query->whereIn('key', [
                    RoleKey::SuperAdmin->value,
                    RoleKey::ClinicAdmin->value,
                    RoleKey::Receptionist->value,
                    RoleKey::Doctor->value,
                ]));
        }

        $higherPriorityRoles = match ($role) {
            AdminAssignableRole::ClinicAdmin => [RoleKey::SuperAdmin->value],
            AdminAssignableRole::Receptionist => [RoleKey::SuperAdmin->value, RoleKey::ClinicAdmin->value],
            AdminAssignableRole::Doctor => [RoleKey::SuperAdmin->value, RoleKey::ClinicAdmin->value],
            default => [],
        };

        return $query
            ->whereHas('roles', fn (Builder $query) => $query->where('key', $role->value))
            ->when($higherPriorityRoles !== [], fn (Builder $query) => $query->whereDoesntHave(
                'roles',
                fn (Builder $query) => $query->whereIn('key', $higherPriorityRoles),
            ));
    }
}
