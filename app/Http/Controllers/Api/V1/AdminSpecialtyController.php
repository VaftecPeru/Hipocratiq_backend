<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminSpecialtyStoreRequest;
use App\Http\Requests\Api\V1\AdminSpecialtyUpdateRequest;
use App\Models\Specialty;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AdminSpecialtyController extends Controller
{
    public function index(): JsonResponse
    {
        $specialties = Specialty::query()
            ->with('parent:id,name')
            ->withCount([
                'subspecialties',
                'doctorProfiles as doctor_primary_count',
                'doctorSubspecialtyProfiles as doctor_subspecialty_count',
                'patientIntakes as patient_primary_count',
                'patientSubspecialtyIntakes as patient_subspecialty_count',
            ])
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->get()
            ->map(fn (Specialty $specialty): array => $this->serializeSpecialty($specialty))
            ->values()
            ->all();

        return response()->json(['success' => true, 'data' => $specialties]);
    }

    public function store(AdminSpecialtyStoreRequest $request, RecordAdminAuditEvent $audit): JsonResponse
    {
        $data = $request->validated();
        $parent = $this->parent($data['parent_id'] ?? null);
        $isActive = $data['is_active'] ?? true;
        $this->assertActiveParent($parent, $isActive);

        $specialty = Specialty::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'parent_id' => $parent?->getKey(),
            'is_active' => $isActive,
        ]);
        $specialty->load('parent:id,name');
        $this->loadUsageCounts($specialty);

        $audit->handle(
            $request->user(),
            $request,
            'specialties',
            'specialty_created',
            Specialty::class,
            $specialty->id,
            $specialty->name,
            metadata: ['code' => $specialty->code, 'parent_id' => $specialty->parent_id],
            critical: true,
        );

        return response()->json(['success' => true, 'data' => $this->serializeSpecialty($specialty)], 201);
    }

    public function update(
        AdminSpecialtyUpdateRequest $request,
        Specialty $specialty,
        RecordAdminAuditEvent $audit,
    ): JsonResponse {
        $data = $request->validated();
        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $specialty->parent_id;
        $parent = $this->parent($parentId);
        $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $specialty->is_active;

        if (! $isActive && $specialty->subspecialties()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'is_active' => ['Desactiva primero las subespecialidades activas antes de desactivar esta especialidad.'],
            ]);
        }

        $this->assertActiveParent($parent, $isActive);
        $before = $specialty->only(['code', 'name', 'parent_id', 'is_active']);
        $specialty->update($data);
        $specialty->load('parent:id,name');
        $this->loadUsageCounts($specialty);

        $audit->handle(
            $request->user(),
            $request,
            'specialties',
            'specialty_updated',
            Specialty::class,
            $specialty->id,
            $specialty->name,
            metadata: ['before' => $before, 'after' => $specialty->only(['code', 'name', 'parent_id', 'is_active'])],
            critical: true,
        );

        return response()->json(['success' => true, 'data' => $this->serializeSpecialty($specialty)]);
    }

    /** @return array<string, mixed> */
    private function serializeSpecialty(Specialty $specialty): array
    {
        $doctorCount = (int) ($specialty->doctor_primary_count ?? 0) + (int) ($specialty->doctor_subspecialty_count ?? 0);
        $patientIntakeCount = (int) ($specialty->patient_primary_count ?? 0) + (int) ($specialty->patient_subspecialty_count ?? 0);

        return [
            'id' => $specialty->id,
            'code' => $specialty->code,
            'name' => $specialty->name,
            'parent_id' => $specialty->parent_id,
            'parent' => $specialty->parent?->only(['id', 'name']),
            'is_active' => (bool) $specialty->is_active,
            'subspecialties_count' => (int) ($specialty->subspecialties_count ?? 0),
            'usage' => [
                'doctors' => $doctorCount,
                'patient_intakes' => $patientIntakeCount,
            ],
        ];
    }

    private function parent(int|string|null $parentId): ?Specialty
    {
        return $parentId === null ? null : Specialty::query()->findOrFail($parentId);
    }

    private function assertActiveParent(?Specialty $parent, bool $isActive): void
    {
        if ($isActive && $parent !== null && ! $parent->is_active) {
            throw ValidationException::withMessages([
                'parent_id' => ['No puedes activar una especialidad bajo un padre desactivado.'],
            ]);
        }
    }

    private function loadUsageCounts(Specialty $specialty): void
    {
        $specialty->loadCount([
            'subspecialties',
            'doctorProfiles as doctor_primary_count',
            'doctorSubspecialtyProfiles as doctor_subspecialty_count',
            'patientIntakes as patient_primary_count',
            'patientSubspecialtyIntakes as patient_subspecialty_count',
        ]);
    }
}
