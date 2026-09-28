<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuthenticationContext;
use App\Enums\ClinicalCodingCategory;
use App\Enums\RoleKey;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicalCoding;
use App\Models\Consultation;
use App\Models\DoctorPrescription;
use App\Models\LaboratoryOrder;
use App\Models\PatientConsent;
use App\Models\PatientProfile;
use App\Models\User;
use App\Support\Clinical\FhirResourceMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FhirReadController extends Controller
{
    public function metadata(FhirResourceMapper $mapper): JsonResponse
    {
        return $this->fhir($mapper->capabilityStatement());
    }

    public function patient(Request $request, PatientProfile $patient, FhirResourceMapper $mapper): JsonResponse
    {
        abort_unless($this->canAccessPatient($request, $patient), 404);

        return $this->fhir($mapper->patient($patient));
    }

    public function practitioner(Request $request, User $practitioner, FhirResourceMapper $mapper): JsonResponse
    {
        abort_unless($this->canAccessPractitioner($request, $practitioner), 404);

        return $this->fhir($mapper->practitioner($practitioner));
    }

    public function organization(Request $request, Clinic $organization, FhirResourceMapper $mapper): JsonResponse
    {
        abort_unless($this->canAccessClinic($request, $organization), 404);

        return $this->fhir($mapper->organization($organization));
    }

    public function condition(Request $request, ClinicalCoding $condition, FhirResourceMapper $mapper): JsonResponse
    {
        abort_unless(in_array($condition->category, [
            ClinicalCodingCategory::Diagnosis,
            ClinicalCodingCategory::Problem,
            ClinicalCodingCategory::Symptom,
        ], true), 404);

        $patient = $condition->patientProfile()->first();
        abort_unless($patient !== null && $this->canAccessPatient($request, $patient), 404);

        return $this->fhir($mapper->condition($condition));
    }

    public function medicationRequest(
        Request $request,
        DoctorPrescription $medicationRequest,
        FhirResourceMapper $mapper,
    ): JsonResponse {
        $patient = $medicationRequest->patientProfile()->first();
        abort_unless($patient !== null && $this->canAccessPatient($request, $patient), 404);

        return $this->fhir($mapper->medicationRequest($medicationRequest));
    }

    public function serviceRequest(
        Request $request,
        LaboratoryOrder $serviceRequest,
        FhirResourceMapper $mapper,
    ): JsonResponse {
        $patient = $serviceRequest->patientProfile()->first();
        abort_unless($patient !== null && $this->canAccessPatient($request, $patient), 404);

        return $this->fhir($mapper->serviceRequest($serviceRequest));
    }

    public function diagnosticReport(
        Request $request,
        LaboratoryOrder $diagnosticReport,
        FhirResourceMapper $mapper,
    ): JsonResponse {
        $patient = $diagnosticReport->patientProfile()->first();
        abort_unless($patient !== null && $this->canAccessPatient($request, $patient), 404);

        return $this->fhir($mapper->diagnosticReport($diagnosticReport));
    }

    public function consent(Request $request, PatientConsent $consent, FhirResourceMapper $mapper): JsonResponse
    {
        $patient = $consent->patientProfile()->first();
        abort_unless($patient !== null && $this->canAccessPatient($request, $patient), 404);

        return $this->fhir($mapper->consent($consent));
    }

    public function appointment(Request $request, Appointment $appointment, FhirResourceMapper $mapper): JsonResponse
    {
        abort_unless($this->canAccessAppointment($request, $appointment), 404);

        return $this->fhir($mapper->appointment($appointment));
    }

    public function encounter(Request $request, Consultation $encounter, FhirResourceMapper $mapper): JsonResponse
    {
        abort_unless($this->canAccessEncounter($request, $encounter), 404);

        return $this->fhir($mapper->encounter($encounter));
    }

    private function canAccessPatient(Request $request, PatientProfile $patient): bool
    {
        $user = $request->user();

        if ($user->hasRole(RoleKey::SuperAdmin)) {
            return true;
        }

        $context = (string) $request->attributes->get('actor_context');

        if ($context === AuthenticationContext::Patient->value) {
            return $patient->user_id === $user->id;
        }

        if ($context === AuthenticationContext::Doctor->value) {
            return $patient->owner_doctor_user_id === $user->id
                || $patient->consultations()->where('doctor_user_id', $user->id)->exists()
                || $patient->appointments()->where('doctor_user_id', $user->id)->exists();
        }

        return $patient->clinic_id !== null && $this->hasClinicMembership($user, $patient->clinic_id);
    }

    private function canAccessPractitioner(Request $request, User $practitioner): bool
    {
        $user = $request->user();

        if ($user->hasRole(RoleKey::SuperAdmin) || $user->id === $practitioner->id) {
            return true;
        }

        $profile = $practitioner->doctorProfile()->first();

        return $profile?->clinic_id !== null && $this->hasClinicMembership($user, $profile->clinic_id);
    }

    private function canAccessClinic(Request $request, Clinic $clinic): bool
    {
        $user = $request->user();

        if ($user->hasRole(RoleKey::SuperAdmin) || $this->hasClinicMembership($user, $clinic->id)) {
            return true;
        }

        if ($user->doctorProfile()->where('clinic_id', $clinic->id)->exists()) {
            return true;
        }

        return $user->patientProfile()->where('clinic_id', $clinic->id)->exists();
    }

    private function canAccessAppointment(Request $request, Appointment $appointment): bool
    {
        $user = $request->user();

        if ($user->hasRole(RoleKey::SuperAdmin) || $appointment->doctor_user_id === $user->id) {
            return true;
        }

        $patient = $appointment->patientProfile()->first();

        return ($patient !== null && $this->canAccessPatient($request, $patient))
            || ($appointment->clinic_id !== null && $this->hasClinicMembership($user, $appointment->clinic_id));
    }

    private function canAccessEncounter(Request $request, Consultation $encounter): bool
    {
        $user = $request->user();

        if ($user->hasRole(RoleKey::SuperAdmin) || $encounter->doctor_user_id === $user->id) {
            return true;
        }

        $patient = $encounter->patientProfile()->first();

        return ($patient !== null && $this->canAccessPatient($request, $patient))
            || ($encounter->clinic_id !== null && $this->hasClinicMembership($user, $encounter->clinic_id));
    }

    private function hasClinicMembership(User $user, int $clinicId): bool
    {
        return $user->clinicMemberships()
            ->where('clinic_id', $clinicId)
            ->where('status', 'active')
            ->exists();
    }

    private function fhir(array $resource): JsonResponse
    {
        return response()
            ->json($resource)
            ->header('Content-Type', 'application/fhir+json; charset=UTF-8');
    }
}
