<?php

namespace App\Support\Clinical;

use App\Enums\AppointmentStatus;
use App\Enums\ConsultationStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Consultation;
use App\Models\PatientConsent;
use App\Models\PatientProfile;
use App\Models\User;

final class FhirResourceMapper
{
    public const FHIR_VERSION = '5.0.0';

    public function capabilityStatement(): array
    {
        return [
            'resourceType' => 'CapabilityStatement',
            'id' => 'hipocratiq-r5-read',
            'status' => 'active',
            'date' => now()->toIso8601String(),
            'kind' => 'instance',
            'software' => [
                'name' => 'HIPOCRATIQ',
                'version' => config('app.version', '1.0.0'),
            ],
            'implementation' => [
                'description' => 'HIPOCRATIQ FHIR R5 read-only facade',
            ],
            'fhirVersion' => self::FHIR_VERSION,
            'format' => ['application/fhir+json', 'json'],
            'rest' => [[
                'mode' => 'server',
                'security' => [
                    'cors' => true,
                    'service' => [[
                        'coding' => [[
                            'system' => 'http://terminology.hl7.org/CodeSystem/restful-security-service',
                            'code' => 'OAuth',
                            'display' => 'OAuth/Sanctum bearer or first-party session',
                        ]],
                    ]],
                ],
                'resource' => array_map(
                    fn (string $type): array => [
                        'type' => $type,
                        'interaction' => [['code' => 'read']],
                        'versioning' => 'versioned-update',
                    ],
                    ['Patient', 'Practitioner', 'Organization', 'Appointment', 'Encounter', 'Consent'],
                ),
            ]],
        ];
    }

    public function patient(PatientProfile $patient): array
    {
        $patient->loadMissing(['user', 'clinic']);

        $identifier = [[
            'system' => 'urn:hipocratiq:patient-profile',
            'value' => (string) $patient->id,
        ]];

        if ($patient->document_number) {
            $identifier[] = [
                'type' => ['text' => $patient->document_type ?: 'Documento'],
                'system' => 'urn:hipocratiq:document:'.strtolower((string) ($patient->user?->country_code ?: 'unknown')),
                'value' => $patient->document_number,
            ];
        }

        return array_filter([
            'resourceType' => 'Patient',
            'id' => (string) $patient->id,
            'meta' => $this->meta($patient->updated_at),
            'identifier' => $identifier,
            'active' => true,
            'name' => $patient->user?->name ? [['use' => 'official', 'text' => $patient->user->name]] : null,
            'telecom' => $this->telecom($patient->user?->phone_number, $patient->user?->email),
            'gender' => $this->gender($patient->sex),
            'birthDate' => $patient->date_of_birth?->format('Y-m-d'),
            'address' => $patient->address ? [['text' => $patient->address]] : null,
            'managingOrganization' => $patient->clinic_id ? [
                'reference' => 'Organization/'.$patient->clinic_id,
                'display' => $patient->clinic?->name,
            ] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function practitioner(User $doctor): array
    {
        $doctor->loadMissing('doctorProfile');
        $profile = $doctor->doctorProfile;

        $identifiers = [];
        if ($profile?->medical_license) {
            $country = strtoupper((string) ($profile->medical_license_country_code ?: $doctor->country_code ?: 'XX'));
            $identifiers[] = [
                'system' => $country === 'MX'
                    ? 'urn:mx:sep:dgp:cedula-profesional'
                    : 'urn:hipocratiq:medical-license:'.strtolower($country),
                'value' => $profile->medical_license,
            ];
        }

        return array_filter([
            'resourceType' => 'Practitioner',
            'id' => (string) $doctor->id,
            'meta' => $this->meta($doctor->updated_at),
            'identifier' => $identifiers ?: null,
            'active' => true,
            'name' => [['use' => 'official', 'text' => $doctor->name]],
            'telecom' => $this->telecom($doctor->phone_number, $doctor->email),
            'qualification' => $profile?->specialty ? [[
                'code' => ['text' => $profile->specialty],
            ]] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function organization(Clinic $clinic): array
    {
        $identifiers = [];
        if ($clinic->tax_id) {
            $identifiers[] = [
                'system' => 'urn:hipocratiq:tax-id:'.strtolower((string) ($clinic->country_code ?: 'unknown')),
                'value' => $clinic->tax_id,
            ];
        }

        return array_filter([
            'resourceType' => 'Organization',
            'id' => (string) $clinic->id,
            'meta' => $this->meta($clinic->updated_at),
            'identifier' => $identifiers ?: null,
            'active' => true,
            'name' => $clinic->name,
            'telecom' => $clinic->contact_phone ? [[
                'system' => 'phone',
                'value' => $clinic->contact_phone,
                'use' => 'work',
            ]] : null,
            'address' => $clinic->address ? [[
                'text' => $clinic->address,
                'country' => $clinic->country_code,
            ]] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function appointment(Appointment $appointment): array
    {
        $appointment->loadMissing(['patientProfile.user', 'doctor', 'clinic']);

        return array_filter([
            'resourceType' => 'Appointment',
            'id' => (string) $appointment->id,
            'meta' => $this->meta($appointment->updated_at),
            'status' => $this->appointmentStatus($appointment->status),
            'description' => $appointment->appointment_type,
            'start' => $appointment->scheduled_at?->toIso8601String(),
            'participant' => array_values(array_filter([
                $appointment->patient_profile_id ? [
                    'actor' => [
                        'reference' => 'Patient/'.$appointment->patient_profile_id,
                        'display' => $appointment->patientProfile?->user?->name,
                    ],
                    'status' => 'accepted',
                ] : null,
                $appointment->doctor_user_id ? [
                    'actor' => [
                        'reference' => 'Practitioner/'.$appointment->doctor_user_id,
                        'display' => $appointment->doctor?->name,
                    ],
                    'status' => 'accepted',
                ] : null,
            ])),
            'serviceCategory' => $appointment->clinic_id ? [[
                'concept' => [
                    'text' => $appointment->clinic?->name,
                ],
            ]] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function consent(PatientConsent $consent): array
    {
        $consent->loadMissing(['patientProfile.user', 'clinic']);

        return array_filter([
            'resourceType' => 'Consent',
            'id' => (string) $consent->id,
            'meta' => $this->meta($consent->updated_at),
            'status' => match ($consent->status->value) {
                'granted' => 'active',
                'revoked', 'expired' => 'inactive',
                default => 'unknown',
            },
            'category' => [[
                'text' => $consent->scope->value,
            ]],
            'subject' => [
                'reference' => 'Patient/'.$consent->patient_profile_id,
                'display' => $consent->patientProfile?->user?->name,
            ],
            'date' => $consent->created_at?->toIso8601String(),
            'period' => array_filter([
                'start' => $consent->effective_from?->toIso8601String(),
                'end' => $consent->effective_until?->toIso8601String(),
            ]),
            'organization' => $consent->clinic_id ? [[
                'reference' => 'Organization/'.$consent->clinic_id,
                'display' => $consent->clinic?->name,
            ]] : null,
            'sourceReference' => [
                'reference' => 'urn:hipocratiq:policy:'.$consent->policy_version,
                'display' => 'HIPOCRATIQ policy '.$consent->policy_version,
            ],
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function encounter(Consultation $consultation): array
    {
        $consultation->loadMissing(['patientProfile.user', 'doctor', 'clinic', 'appointment']);

        return array_filter([
            'resourceType' => 'Encounter',
            'id' => (string) $consultation->id,
            'meta' => $this->meta($consultation->updated_at),
            'status' => $this->encounterStatus($consultation->status),
            'class' => [[
                'coding' => [[
                    'system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                    'code' => 'AMB',
                    'display' => 'ambulatory',
                ]],
            ]],
            'subject' => [
                'reference' => 'Patient/'.$consultation->patient_profile_id,
                'display' => $consultation->patientProfile?->user?->name,
            ],
            'participant' => [[
                'actor' => [
                    'reference' => 'Practitioner/'.$consultation->doctor_user_id,
                    'display' => $consultation->doctor?->name,
                ],
            ]],
            'appointment' => $consultation->appointment_id ? [[
                'reference' => 'Appointment/'.$consultation->appointment_id,
            ]] : null,
            'serviceProvider' => $consultation->clinic_id ? [
                'reference' => 'Organization/'.$consultation->clinic_id,
                'display' => $consultation->clinic?->name,
            ] : null,
            'actualPeriod' => array_filter([
                'start' => $consultation->created_at?->toIso8601String(),
                'end' => ($consultation->signed_at ?? $consultation->completed_at)?->toIso8601String(),
            ]),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function meta(mixed $updatedAt): array
    {
        return array_filter([
            'versionId' => $updatedAt ? (string) $updatedAt->getTimestamp() : null,
            'lastUpdated' => $updatedAt?->toIso8601String(),
            'profile' => ['http://hl7.org/fhir/StructureDefinition/'.'Resource'],
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function telecom(?string $phone, ?string $email): array
    {
        return array_values(array_filter([
            $phone ? ['system' => 'phone', 'value' => $phone] : null,
            $email ? ['system' => 'email', 'value' => $email] : null,
        ]));
    }

    private function gender(?string $sex): string
    {
        return match (strtolower(trim((string) $sex))) {
            'm', 'male', 'masculino', 'hombre' => 'male',
            'f', 'female', 'femenino', 'mujer' => 'female',
            'other', 'otro' => 'other',
            default => 'unknown',
        };
    }

    private function appointmentStatus(AppointmentStatus $status): string
    {
        return match ($status) {
            AppointmentStatus::Pending => 'pending',
            AppointmentStatus::Confirmed => 'booked',
            AppointmentStatus::Waiting, AppointmentStatus::InConsultation => 'arrived',
            AppointmentStatus::Completed => 'fulfilled',
            AppointmentStatus::Canceled => 'cancelled',
            AppointmentStatus::NoShow => 'noshow',
        };
    }

    private function encounterStatus(ConsultationStatus $status): string
    {
        return match ($status) {
            ConsultationStatus::Draft => 'planned',
            ConsultationStatus::InProgress => 'in-progress',
            ConsultationStatus::Completed, ConsultationStatus::Signed => 'completed',
            ConsultationStatus::Canceled => 'cancelled',
        };
    }
}
