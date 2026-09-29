<?php

namespace App\Support\Clinical;

use App\Enums\PatientConsentScope;
use App\Enums\PatientConsentStatus;
use App\Models\PatientConsent;
use App\Models\PatientProfile;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ActivePatientConsent
{
    public function allows(
        PatientProfile $patient,
        PatientConsentScope $scope,
        ?CarbonInterface $at = null,
    ): bool {
        return $this->patientIds(
            patientIds: [$patient->id],
            scope: $scope,
            clinicId: $patient->clinic_id,
            at: $at,
        )->contains($patient->id);
    }

    /**
     * @param  iterable<int|string>  $patientIds
     * @return Collection<int, int>
     */
    public function patientIds(
        iterable $patientIds,
        PatientConsentScope $scope,
        ?int $clinicId,
        ?CarbonInterface $at = null,
    ): Collection {
        $at ??= now();
        $ids = collect($patientIds)
            ->filter(static fn ($id): bool => is_numeric($id))
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return PatientConsent::query()
            ->whereIn('patient_profile_id', $ids)
            ->when(
                $clinicId === null,
                fn (Builder $query): Builder => $query->whereNull('clinic_id'),
                fn (Builder $query): Builder => $query->where('clinic_id', $clinicId),
            )
            ->where('scope', $scope->value)
            ->where('policy_version', (string) config('clinical.consent_policy_version'))
            ->where('status', PatientConsentStatus::Granted->value)
            ->whereNull('revoked_at')
            ->where('effective_from', '<=', $at)
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $at);
            })
            ->pluck('patient_profile_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }
}
