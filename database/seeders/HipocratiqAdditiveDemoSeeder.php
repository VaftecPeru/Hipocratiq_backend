<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\BillingCycle;
use App\Enums\ClinicMembershipStatus;
use App\Enums\ClinicStatus;
use App\Enums\ConsultationStatus;
use App\Enums\DoctorPracticeType;
use App\Enums\OnboardingStatus;
use App\Enums\PlanKey;
use App\Enums\RoleKey;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicalHistoryEntry;
use App\Models\ClinicalHistoryRecord;
use App\Models\ClinicalHistorySection;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\Consultation;
use App\Models\DoctorProfile;
use App\Models\DoctorSubscription;
use App\Models\PatientIntake;
use App\Models\PatientProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\Clinical\DoctorPreferences;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class HipocratiqAdditiveDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Shared only by synthetic staff accounts created for local testing.
     */
    private const string PASSWORD = 'TestLocal#2026!';

    private const string CLINIC_SLUG = 'hipocratiq-additive-demo';

    private const string CLINIC_TAX_ID = 'DEMO-HIPOCRATIQ-ADDITIVE-001';

    private const string CLINIC_SUBSCRIPTION_REFERENCE = 'demo-additive-clinic-subscription-001';

    private const string DOCTOR_SUBSCRIPTION_REFERENCE = 'demo-additive-doctor-subscription-001';

    private const string PAYMENT_IDEMPOTENCY_KEY = 'demo-additive-payment-001';

    private const string PAYMENT_REFERENCE = 'demo-additive-payment-001';

    private const string APPOINTMENT_NOTE_PREFIX = 'hipocratiq-additive-demo:appointment:';

    private const string CLINICAL_HISTORY_SOURCE = 'demo_additive';

    public function run(): void
    {
        $this->call([
            DatabaseSeeder::class,
            ClinicalHistorySectionSeeder::class,
        ]);

        DB::transaction(function (): void {
            $this->seedScenario($this->demoPassword());
        });

        $this->command?->info('HipocratiQ additive demo data seeded without destructive operations.');
    }

    private function seedScenario(string $password): void
    {
        $plan = Plan::query()->where('key', PlanKey::Professional->value)->firstOrFail();
        $superAdminRole = Role::query()->where('key', RoleKey::SuperAdmin->value)->firstOrFail();
        $clinicAdminRole = Role::query()->where('key', RoleKey::ClinicAdmin->value)->firstOrFail();
        $receptionistRole = Role::query()->where('key', RoleKey::Receptionist->value)->firstOrFail();
        $doctorRole = Role::query()->where('key', RoleKey::Doctor->value)->firstOrFail();
        $specialties = Specialty::query()
            ->whereIn('code', ['medicina_interna', 'cardiologia', 'dermatologia'])
            ->get()
            ->keyBy('code');
        $sections = ClinicalHistorySection::query()->where('is_active', true)->get()->keyBy('key');

        $internalMedicine = $specialties->get('medicina_interna');
        $cardiology = $specialties->get('cardiologia');
        $dermatology = $specialties->get('dermatologia');

        if (! $internalMedicine instanceof Specialty
            || ! $cardiology instanceof Specialty
            || ! $dermatology instanceof Specialty) {
            throw new RuntimeException('The demo seeder requires the canonical specialty catalog.');
        }

        $clinic = $this->upsertClinic();
        $clinicSubscription = ClinicSubscription::query()->updateOrCreate(
            ['provider_reference' => self::CLINIC_SUBSCRIPTION_REFERENCE],
            [
                'clinic_id' => $clinic->id,
                'plan_id' => $plan->id,
                'billing_cycle' => BillingCycle::Monthly,
                'status' => SubscriptionStatus::Active,
                'auto_renew' => true,
                'starts_at' => now()->subDays(30),
                'ends_at' => null,
                'provider' => 'demo',
            ],
        );

        SubscriptionPayment::query()->updateOrCreate(
            ['idempotency_key' => self::PAYMENT_IDEMPOTENCY_KEY],
            [
                'clinic_subscription_id' => $clinicSubscription->id,
                'amount_cents' => $plan->monthly_price_cents,
                'currency' => $plan->currency,
                'status' => 'succeeded',
                'provider' => 'demo',
                'provider_reference' => self::PAYMENT_REFERENCE,
                'paid_at' => now()->subDay(),
            ],
        );

        $superAdmin = $this->upsertUser([
            'name' => 'Demo Platform Administrator',
            'email' => 'super@test.local',
            'phone_number' => '+51990010001',
            'country_code' => 'PE',
        ], $password);
        $superAdmin->roles()->syncWithoutDetaching([$superAdminRole->id]);

        $clinicAdmin = $this->upsertUser([
            'name' => 'Demo Clinic Administrator',
            'email' => 'clinic@test.local',
            'phone_number' => '+51990010002',
            'country_code' => 'PE',
        ], $password);
        $clinicAdmin->roles()->syncWithoutDetaching([$clinicAdminRole->id]);
        $this->upsertMembership($clinic, $clinicAdmin, $clinicAdminRole);

        $receptionist = $this->upsertUser([
            'name' => 'Demo Front Desk Coordinator',
            'email' => 'recep@test.local',
            'phone_number' => '+51990010003',
            'country_code' => 'PE',
        ], $password);
        $receptionist->roles()->syncWithoutDetaching([$receptionistRole->id]);
        $this->upsertMembership($clinic, $receptionist, $receptionistRole);

        $clinicDoctor = $this->upsertUser([
            'name' => 'Demo Clinic Physician',
            'email' => 'doctor@test.local',
            'phone_number' => '+51990010004',
            'country_code' => 'PE',
        ], $password);
        $clinicDoctor->roles()->syncWithoutDetaching([$doctorRole->id]);
        $this->upsertMembership($clinic, $clinicDoctor, $doctorRole);
        DoctorProfile::query()->updateOrCreate(
            ['user_id' => $clinicDoctor->id],
            [
                'clinic_id' => $clinic->id,
                'medical_license' => 'DEMO-CMP-CL-001',
                'specialty' => $internalMedicine->name,
                'specialty_id' => $internalMedicine->id,
                'subspecialty_id' => $cardiology->id,
                'practice_type' => DoctorPracticeType::Clinic,
                'preferences' => DoctorPreferences::defaults(),
            ],
        );

        $independentDoctor = $this->upsertUser([
            'name' => 'Demo Independent Physician',
            'email' => 'inddoc@test.local',
            'phone_number' => '+51990010005',
            'country_code' => 'PE',
        ], $password);
        $independentDoctor->roles()->syncWithoutDetaching([$doctorRole->id]);
        $independentProfile = DoctorProfile::query()->updateOrCreate(
            ['user_id' => $independentDoctor->id],
            [
                'clinic_id' => null,
                'medical_license' => 'DEMO-CMP-IN-001',
                'specialty' => $internalMedicine->name,
                'specialty_id' => $internalMedicine->id,
                'subspecialty_id' => $dermatology->id,
                'practice_type' => DoctorPracticeType::Independent,
                'preferences' => DoctorPreferences::defaults(),
            ],
        );
        DoctorSubscription::query()->updateOrCreate(
            ['provider_reference' => self::DOCTOR_SUBSCRIPTION_REFERENCE],
            [
                'doctor_profile_id' => $independentProfile->id,
                'plan_id' => $plan->id,
                'billing_cycle' => BillingCycle::Monthly,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->subDays(30),
                'ends_at' => null,
                'provider' => 'demo',
            ],
        );

        $clinicPatients = [];
        foreach ([
            [
                'key' => 'clinic-primary',
                'name' => 'Demo Patient Primary',
                'email' => 'pat1@test.local',
                'phone_number' => '+51990010006',
                'date_of_birth' => '1987-04-12',
                'sex' => 'Femenino',
                'document_number' => 'DEMO-PE-0001',
                'address' => 'Synthetic Demo Avenue 101, Lima',
                'emergency_contact' => 'Demo Contact 01',
                'insurance' => 'Demo Salud Plan',
                'is_returning_patient' => true,
                'whatsapp_consent' => true,
            ],
            [
                'key' => 'clinic-follow-up',
                'name' => 'Demo Patient Follow Up',
                'email' => 'pat2@test.local',
                'phone_number' => '+51990010007',
                'date_of_birth' => '1991-09-23',
                'sex' => 'Masculino',
                'document_number' => 'DEMO-PE-0002',
                'address' => 'Synthetic Demo Avenue 102, Lima',
                'emergency_contact' => 'Demo Contact 02',
                'insurance' => 'Demo Salud Plan',
                'is_returning_patient' => true,
                'whatsapp_consent' => false,
            ],
            [
                'key' => 'clinic-triage',
                'name' => 'Demo Patient Triage',
                'email' => 'pat3@test.local',
                'phone_number' => '+51990010008',
                'date_of_birth' => '1979-01-30',
                'sex' => 'Masculino',
                'document_number' => 'DEMO-PE-0003',
                'address' => 'Synthetic Demo Avenue 103, Lima',
                'emergency_contact' => 'Demo Contact 03',
                'insurance' => 'Demo Salud Plan',
                'is_returning_patient' => false,
                'whatsapp_consent' => true,
            ],
            [
                'key' => 'clinic-preventive',
                'name' => 'Demo Patient Preventive',
                'email' => 'pat4@test.local',
                'phone_number' => '+51990010009',
                'date_of_birth' => '1996-12-05',
                'sex' => 'Femenino',
                'document_number' => 'DEMO-PE-0004',
                'address' => 'Synthetic Demo Avenue 104, Lima',
                'emergency_contact' => 'Demo Contact 04',
                'insurance' => null,
                'is_returning_patient' => false,
                'whatsapp_consent' => true,
            ],
            [
                'key' => 'clinic-new',
                'name' => 'Demo Patient New Intake',
                'email' => 'pat5@test.local',
                'phone_number' => '+51990010010',
                'date_of_birth' => '2000-06-18',
                'sex' => 'Otro',
                'document_number' => 'DEMO-PE-0005',
                'address' => 'Synthetic Demo Avenue 105, Lima',
                'emergency_contact' => 'Demo Contact 05',
                'insurance' => 'Demo Salud Plan',
                'is_returning_patient' => false,
                'whatsapp_consent' => false,
            ],
        ] as $definition) {
            $patientUser = $this->upsertUser([
                'name' => $definition['name'],
                'email' => $definition['email'],
                'phone_number' => $definition['phone_number'],
                'country_code' => 'PE',
            ], null);
            $clinicPatients[$definition['key']] = $this->upsertPatient(
                $patientUser,
                $clinic,
                null,
                [
                    'document_type' => 'DEMO-ID',
                    'document_number' => $definition['document_number'],
                    'date_of_birth' => $definition['date_of_birth'],
                    'sex' => $definition['sex'],
                    'address' => $definition['address'],
                    'emergency_contact' => $definition['emergency_contact'],
                    'insurance' => $definition['insurance'],
                    'is_returning_patient' => $definition['is_returning_patient'],
                    'whatsapp_consent' => $definition['whatsapp_consent'],
                ],
            );
        }

        $independentPatientUser = $this->upsertUser([
            'name' => 'Demo Independent Patient',
            'email' => 'indpat@test.local',
            'phone_number' => '+51990010011',
            'country_code' => 'PE',
        ], null);
        $independentPatient = $this->upsertPatient(
            $independentPatientUser,
            null,
            $independentDoctor,
            [
                'document_type' => 'DEMO-ID',
                'document_number' => 'DEMO-PE-0006',
                'date_of_birth' => '1993-08-27',
                'sex' => 'Femenino',
                'address' => 'Synthetic Independent Avenue 201, Lima',
                'emergency_contact' => 'Demo Contact 06',
                'insurance' => null,
                'is_returning_patient' => false,
                'whatsapp_consent' => true,
            ],
        );

        $baseDate = now()->startOfDay();
        $clinicAppointments = $this->seedAppointments(
            $clinic,
            $clinicDoctor,
            $clinicPatients,
            $baseDate,
            [
                ['key' => 'clinic-yesterday-completed', 'offset' => -1, 'hour' => 10, 'minute' => 0, 'patient' => 'clinic-primary', 'status' => AppointmentStatus::Completed, 'type' => 'consultation'],
                ['key' => 'clinic-today-completed', 'offset' => 0, 'hour' => 8, 'minute' => 30, 'patient' => 'clinic-primary', 'status' => AppointmentStatus::Completed, 'type' => 'follow_up'],
                ['key' => 'clinic-today-confirmed', 'offset' => 0, 'hour' => 9, 'minute' => 0, 'patient' => 'clinic-follow-up', 'status' => AppointmentStatus::Confirmed, 'type' => 'consultation'],
                ['key' => 'clinic-today-waiting', 'offset' => 0, 'hour' => 10, 'minute' => 0, 'patient' => 'clinic-triage', 'status' => AppointmentStatus::Waiting, 'type' => 'triage'],
                ['key' => 'clinic-today-in-consultation', 'offset' => 0, 'hour' => 11, 'minute' => 0, 'patient' => 'clinic-preventive', 'status' => AppointmentStatus::InConsultation, 'type' => 'consultation'],
                ['key' => 'clinic-today-pending', 'offset' => 0, 'hour' => 12, 'minute' => 0, 'patient' => 'clinic-new', 'status' => AppointmentStatus::Pending, 'type' => 'consultation'],
                ['key' => 'clinic-today-canceled', 'offset' => 0, 'hour' => 13, 'minute' => 0, 'patient' => 'clinic-primary', 'status' => AppointmentStatus::Canceled, 'type' => 'consultation'],
                ['key' => 'clinic-next-confirmed', 'offset' => 1, 'hour' => 9, 'minute' => 30, 'patient' => 'clinic-follow-up', 'status' => AppointmentStatus::Confirmed, 'type' => 'follow_up'],
                ['key' => 'clinic-future-no-show', 'offset' => 2, 'hour' => 15, 'minute' => 0, 'patient' => 'clinic-triage', 'status' => AppointmentStatus::NoShow, 'type' => 'consultation'],
            ],
        );
        $independentAppointments = $this->seedAppointments(
            null,
            $independentDoctor,
            ['independent' => $independentPatient],
            $baseDate,
            [
                ['key' => 'independent-yesterday-completed', 'offset' => -1, 'hour' => 16, 'minute' => 0, 'patient' => 'independent', 'status' => AppointmentStatus::Completed, 'type' => 'consultation'],
                ['key' => 'independent-today-pending', 'offset' => 0, 'hour' => 16, 'minute' => 0, 'patient' => 'independent', 'status' => AppointmentStatus::Pending, 'type' => 'consultation'],
                ['key' => 'independent-next-confirmed', 'offset' => 2, 'hour' => 9, 'minute' => 0, 'patient' => 'independent', 'status' => AppointmentStatus::Confirmed, 'type' => 'follow_up'],
            ],
        );

        $this->upsertIntake(
            $clinic,
            $clinicPatients['clinic-primary'],
            $receptionist,
            $clinicDoctor,
            $internalMedicine,
            $cardiology,
            'primary',
            [
                'reason_category' => 'preventive',
                'objective' => 'Synthetic demo intake for preventive cardiovascular follow-up.',
                'observations' => 'Synthetic triage data for the additive local demo.',
                'temperature_c' => 36.7,
                'systolic_bp' => 118,
                'diastolic_bp' => 76,
                'heart_rate' => 72,
                'respiratory_rate' => 16,
                'oxygen_saturation' => 98,
                'triage_priority' => 'routine',
                'triage_notes' => 'No immediate warning signs in synthetic demo data.',
            ],
        );
        $this->upsertIntake(
            $clinic,
            $clinicPatients['clinic-follow-up'],
            $receptionist,
            $clinicDoctor,
            $internalMedicine,
            $cardiology,
            'follow-up',
            [
                'reason_category' => 'follow_up',
                'objective' => 'Synthetic demo intake for a scheduled clinical follow-up.',
                'observations' => 'Patient is waiting for the assigned physician in the demo flow.',
                'temperature_c' => 37.1,
                'systolic_bp' => 126,
                'diastolic_bp' => 82,
                'heart_rate' => 79,
                'respiratory_rate' => 17,
                'oxygen_saturation' => 97,
                'triage_priority' => 'routine',
                'triage_notes' => 'Routine follow-up in synthetic demo data.',
            ],
        );
        $this->upsertIntake(
            $clinic,
            $clinicPatients['clinic-triage'],
            $receptionist,
            $clinicDoctor,
            $internalMedicine,
            $cardiology,
            'triage',
            [
                'reason_category' => 'symptoms',
                'objective' => 'Synthetic demo intake for an urgent symptom evaluation.',
                'observations' => 'Synthetic triage record intentionally marked urgent for UI coverage.',
                'temperature_c' => 37.8,
                'systolic_bp' => 142,
                'diastolic_bp' => 90,
                'heart_rate' => 96,
                'respiratory_rate' => 20,
                'oxygen_saturation' => 95,
                'triage_priority' => 'urgent',
                'triage_notes' => 'Review promptly; all values are synthetic demo data.',
            ],
        );

        $clinicRecord = $this->upsertClinicalHistoryRecord(
            $clinic,
            $clinicPatients['clinic-primary'],
            $clinicDoctor,
            $clinicAppointments['clinic-yesterday-completed'],
            'Preventive synthetic demo consultation with complete clinical history.',
        );
        $this->syncClinicalHistoryEntries(
            $clinicRecord,
            $this->clinicalHistoryValues(
                $clinicPatients['clinic-primary'],
                $clinicDoctor,
                $clinic->name,
                'DEMO-CL-0001',
                now()->subDay(),
            ),
            $sections,
        );
        $this->upsertConsultation(
            $clinicRecord,
            $clinic,
            $clinicDoctor,
            $clinicPatients['clinic-primary'],
            $clinicAppointments['clinic-yesterday-completed'],
            ConsultationStatus::Signed,
            'Synthetic preventive consultation for the clinic demo.',
            now()->subDay()->addMinutes(45),
            now()->subDay()->addMinutes(30),
        );

        $independentRecord = $this->upsertClinicalHistoryRecord(
            null,
            $independentPatient,
            $independentDoctor,
            $independentAppointments['independent-yesterday-completed'],
            'Independent synthetic demo consultation awaiting signature.',
        );
        $this->syncClinicalHistoryEntries(
            $independentRecord,
            $this->clinicalHistoryValues(
                $independentPatient,
                $independentDoctor,
                'Independent Demo Practice',
                'DEMO-IN-0001',
                now()->subDay(),
            ),
            $sections,
        );
        $this->upsertConsultation(
            $independentRecord,
            null,
            $independentDoctor,
            $independentPatient,
            $independentAppointments['independent-yesterday-completed'],
            ConsultationStatus::Completed,
            'Synthetic independent consultation awaiting signature.',
            null,
            now()->subDay()->addMinutes(30),
        );
    }

    private function demoPassword(): string
    {
        return self::PASSWORD;
    }

    private function upsertClinic(): Clinic
    {
        $existingBySlug = Clinic::query()->where('slug', self::CLINIC_SLUG)->first();
        if ($existingBySlug !== null && $existingBySlug->tax_id !== self::CLINIC_TAX_ID) {
            throw new RuntimeException('The additive demo clinic slug is already owned by another record.');
        }

        if (Clinic::query()
            ->where('tax_id', self::CLINIC_TAX_ID)
            ->where('slug', '<>', self::CLINIC_SLUG)
            ->exists()) {
            throw new RuntimeException('The additive demo clinic tax identifier is already in use.');
        }

        return Clinic::query()->updateOrCreate(
            ['slug' => self::CLINIC_SLUG],
            [
                'name' => 'HipocratiQ Additive Demo Clinic',
                'tax_id' => self::CLINIC_TAX_ID,
                'country_code' => 'PE',
                'contact_phone' => '+51990010000',
                'address' => 'Synthetic Demo Avenue 100, Lima',
                'status' => ClinicStatus::Active,
                'preferences' => [
                    'maintenance' => false,
                    'audit' => true,
                    'signatures' => true,
                    'backups' => true,
                ],
            ],
        );
    }

    /**
     * @param  array{name: string, email: string, phone_number: string, country_code: string}  $attributes
     */
    private function upsertUser(array $attributes, ?string $password): User
    {
        $this->assertUserIdentifiersAvailable($attributes['email'], $attributes['phone_number']);

        $user = User::query()->firstOrNew(['email' => $attributes['email']]);
        $user->fill([
            'name' => $attributes['name'],
            'phone_number' => $attributes['phone_number'],
            'country_code' => $attributes['country_code'],
            'email_verified_at' => now(),
            'status' => UserStatus::Active,
            'onboarding_status' => OnboardingStatus::Completed,
            'phone_verified_at' => now(),
            'last_login_at' => null,
        ]);

        if ($password !== null
            && (! is_string($user->password) || ! Hash::check($password, $user->password))) {
            $user->password = Hash::make($password);
        }

        $user->save();

        return $user;
    }

    private function assertUserIdentifiersAvailable(string $email, string $phoneNumber): void
    {
        $existingUser = User::query()->where('email', $email)->first();
        if ($existingUser !== null && ! str_starts_with((string) $existingUser->name, 'Demo ')) {
            throw new RuntimeException('An additive demo email is already owned by another account.');
        }

        $phoneCollision = User::query()
            ->where('phone_number', $phoneNumber)
            ->where(function ($query) use ($email): void {
                $query->whereNull('email')->orWhere('email', '<>', $email);
            })
            ->exists();

        if ($phoneCollision) {
            throw new RuntimeException('An additive demo phone identifier is already in use.');
        }
    }

    private function upsertMembership(Clinic $clinic, User $user, Role $role): ClinicMembership
    {
        return ClinicMembership::query()->updateOrCreate(
            ['clinic_id' => $clinic->id, 'user_id' => $user->id],
            ['role_id' => $role->id, 'status' => ClinicMembershipStatus::Active],
        );
    }

    /** @param array<string, mixed> $attributes */
    private function upsertPatient(
        User $user,
        ?Clinic $clinic,
        ?User $ownerDoctor,
        array $attributes,
    ): PatientProfile {
        return PatientProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                ...$attributes,
                'clinic_id' => $clinic?->id,
                'owner_doctor_user_id' => $ownerDoctor?->id,
            ],
        );
    }

    /**
     * @param  array<string, PatientProfile>  $patients
     * @param  array<int, array{key: string, offset: int, hour: int, minute: int, patient: string, status: AppointmentStatus, type: string}>  $definitions
     * @return array<string, Appointment>
     */
    private function seedAppointments(
        ?Clinic $clinic,
        User $doctor,
        array $patients,
        Carbon $baseDate,
        array $definitions,
    ): array {
        $appointments = [];

        foreach ($definitions as $definition) {
            $appointments[$definition['key']] = $this->upsertAppointment(
                $clinic,
                $patients[$definition['patient']],
                $doctor,
                $baseDate->copy()->addDays($definition['offset'])->setTime($definition['hour'], $definition['minute']),
                $definition['status'],
                $definition['type'],
                $definition['key'],
            );
        }

        return $appointments;
    }

    private function upsertAppointment(
        ?Clinic $clinic,
        PatientProfile $patient,
        User $doctor,
        Carbon $scheduledAt,
        AppointmentStatus $status,
        string $appointmentType,
        string $key,
    ): Appointment {
        return Appointment::query()->updateOrCreate(
            ['notes' => self::APPOINTMENT_NOTE_PREFIX.$key],
            [
                'clinic_id' => $clinic?->id,
                'patient_profile_id' => $patient->id,
                'doctor_user_id' => $doctor->id,
                'scheduled_at' => $scheduledAt,
                'status' => $status,
                'appointment_type' => $appointmentType,
            ],
        );
    }

    /** @param array<string, mixed> $attributes */
    private function upsertIntake(
        Clinic $clinic,
        PatientProfile $patient,
        User $registrar,
        User $doctor,
        Specialty $specialty,
        Specialty $subspecialty,
        string $key,
        array $attributes,
    ): PatientIntake {
        $objective = (string) ($attributes['objective'] ?? '');

        return PatientIntake::query()->updateOrCreate(
            [
                'clinic_id' => $clinic->id,
                'patient_profile_id' => $patient->id,
                'registered_by_user_id' => $registrar->id,
                'objective' => $objective,
            ],
            [
                ...$attributes,
                'requested_doctor_user_id' => $doctor->id,
                'specialty_id' => $specialty->id,
                'subspecialty_id' => $subspecialty->id,
                'reason_category' => $attributes['reason_category'] ?? $key,
            ],
        );
    }

    private function upsertClinicalHistoryRecord(
        ?Clinic $clinic,
        PatientProfile $patient,
        User $doctor,
        Appointment $appointment,
        string $objective,
    ): ClinicalHistoryRecord {
        $query = ClinicalHistoryRecord::query()
            ->where('patient_profile_id', $patient->id)
            ->where('doctor_user_id', $doctor->id)
            ->where('source', self::CLINICAL_HISTORY_SOURCE);

        if ($clinic === null) {
            $query->whereNull('clinic_id');
        } else {
            $query->where('clinic_id', $clinic->id);
        }

        $record = $query->first() ?? new ClinicalHistoryRecord;
        $record->fill([
            'clinic_id' => $clinic?->id,
            'patient_profile_id' => $patient->id,
            'doctor_user_id' => $doctor->id,
            'appointment_id' => $appointment->id,
            'created_by_user_id' => $doctor->id,
            'source' => self::CLINICAL_HISTORY_SOURCE,
            'objective' => $objective,
        ])->save();

        return $record;
    }

    /**
     * @param  Collection<int|string, ClinicalHistorySection>  $sections
     * @param  array<string, array<string, mixed>>  $valuesBySection
     */
    private function syncClinicalHistoryEntries(
        ClinicalHistoryRecord $record,
        array $valuesBySection,
        Collection $sections,
    ): void {
        foreach ($valuesBySection as $sectionKey => $values) {
            $section = $sections->get($sectionKey);
            if (! $section instanceof ClinicalHistorySection) {
                throw new RuntimeException("The clinical history section '{$sectionKey}' is missing.");
            }

            ClinicalHistoryEntry::query()->updateOrCreate(
                [
                    'clinical_history_record_id' => $record->id,
                    'clinical_history_section_id' => $section->id,
                ],
                ['values' => $values],
            );
        }
    }

    private function upsertConsultation(
        ClinicalHistoryRecord $record,
        ?Clinic $clinic,
        User $doctor,
        PatientProfile $patient,
        Appointment $appointment,
        ConsultationStatus $status,
        string $chiefComplaint,
        ?Carbon $signedAt,
        ?Carbon $completedAt,
    ): Consultation {
        $consultation = Consultation::query()
            ->where('clinical_history_record_id', $record->id)
            ->first() ?? new Consultation;
        $consultation->fill([
            'clinic_id' => $clinic?->id,
            'doctor_user_id' => $doctor->id,
            'patient_profile_id' => $patient->id,
            'appointment_id' => $appointment->id,
            'clinical_history_record_id' => $record->id,
            'created_by_user_id' => $doctor->id,
            'status' => $status,
            'chief_complaint' => $chiefComplaint,
            'objective' => $record->objective,
            'signed_by_user_id' => $status === ConsultationStatus::Signed ? $doctor->id : null,
            'signed_at' => $signedAt,
            'completed_at' => $completedAt,
        ])->save();

        return $consultation;
    }

    /** @return array<string, array<string, mixed>> */
    private function clinicalHistoryValues(
        PatientProfile $patient,
        User $doctor,
        string $unit,
        string $recordNumber,
        Carbon $recordedAt,
    ): array {
        $patient->loadMissing('user');
        $patientUser = $patient->user;

        return [
            'record_metadata' => [
                'unit' => $unit,
                'record_number' => $recordNumber,
                'date' => $recordedAt->toDateString(),
                'time' => $recordedAt->format('H:i'),
                'interview_type' => 'directo',
            ],
            'patient_identification' => [
                'name' => $patientUser?->name,
                'age' => $patient->date_of_birth?->age,
                'gender' => strtolower((string) $patient->sex),
                'birth_date' => $patient->date_of_birth?->toDateString(),
                'occupation' => 'Synthetic demo occupation',
                'address' => $patient->address,
                'guardian_name' => null,
                'relationship' => null,
            ],
            'family_history' => [
                'description' => 'Synthetic family history: no relevant hereditary conditions reported.',
            ],
            'non_pathological_history' => [
                'description' => 'Synthetic non-pathological history: regular sleep, balanced meals, no tobacco use reported.',
            ],
            'pathological_history' => [
                'description' => 'Synthetic pathological history: no known allergies or previous surgeries reported.',
            ],
            'gyneco_obstetric_history' => [
                'description' => 'Synthetic gyneco-obstetric history recorded only for demo coverage.',
            ],
            'present_illness' => [
                'reason' => 'Preventive synthetic demo consultation',
                'illness_narrative' => 'Synthetic narrative used to demonstrate the complete clinical-history workflow.',
            ],
            'systems_review' => [
                'cardiovascular' => 'No synthetic warning symptoms reported.',
                'respiratory' => 'No synthetic respiratory symptoms reported.',
                'gastrointestinal' => 'No synthetic gastrointestinal symptoms reported.',
                'genitourinary' => 'No synthetic genitourinary symptoms reported.',
                'hematologic_lymphatic' => 'No synthetic hematologic symptoms reported.',
                'endocrine' => 'No synthetic endocrine symptoms reported.',
                'nervous' => 'No synthetic neurologic symptoms reported.',
                'musculoskeletal' => 'No synthetic musculoskeletal symptoms reported.',
                'skin_mucosa_appendages' => 'No synthetic skin symptoms reported.',
            ],
            'vital_signs' => [
                'blood_pressure' => '118/76 mmHg',
                'temperature' => '36.7 °C',
                'heart_rate' => '72 bpm',
                'respiratory_rate' => '16 rpm',
                'weight' => '68 kg',
                'height' => '1.68 m',
            ],
            'physical_examination' => [
                'habitus_exterior' => 'Synthetic demo examination: alert and oriented.',
                'head' => 'Synthetic demo examination: no relevant findings.',
                'neck' => 'Synthetic demo examination: no relevant findings.',
                'thorax' => 'Synthetic demo examination: symmetric expansion.',
                'abdomen' => 'Synthetic demo examination: soft and non-tender.',
                'genitalia' => 'Not evaluated in this synthetic demo record.',
                'extremities' => 'Synthetic demo examination: no edema observed.',
                'skin' => 'Synthetic demo examination: intact and hydrated.',
            ],
            'labs_imaging' => [
                'results' => 'Synthetic laboratory and imaging results: within expected demo ranges.',
            ],
            'diagnoses_problems' => [
                'diagnoses' => 'Synthetic preventive care assessment; no definitive diagnosis assigned.',
            ],
            'pharmacological_treatment' => [
                'treatment' => 'Synthetic recommendation: continue preventive measures and schedule follow-up.',
            ],
            'prognosis' => [
                'prognosis' => 'Synthetic prognosis: favorable with routine follow-up.',
            ],
            'clinician_identification' => [
                'clinician_name' => $doctor->name,
                'title' => 'Demo physician · synthetic license',
                'unit' => $unit,
            ],
        ];
    }
}
