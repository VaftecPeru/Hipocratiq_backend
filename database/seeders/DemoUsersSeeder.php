<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\DoctorPracticeType;
use App\Enums\OnboardingStatus;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\DoctorProfile;
use App\Models\DoctorSubscription;
use App\Models\PatientProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates demo users for every role with deterministic credentials.
 *
 * Local:      php artisan db:seed --class=DemoUsersSeeder
 * Production: php artisan db:seed --class=DemoUsersSeeder --force
 *
 * All users share the password: Hipocratiq2026!
 */
class DemoUsersSeeder extends Seeder
{
    private const PASSWORD = 'Hipocratiq2026!';

    public function run(): void
    {
        // Ensure base roles and plans exist
        $this->call(DatabaseSeeder::class);

        // Reset demo data: remove existing clinic staff and patients
        // so the demo starts from a clean, deterministic state. User deletes
        // cascade to profiles, memberships, subscriptions, OTP challenges, and
        // sessions (all FKs are cascadeOnDelete on user_id).
        $this->purgeExistingRoleUsers();

        $password = Hash::make(self::PASSWORD);
        $plan = Plan::query()->where('key', 'professional')->firstOrFail();
        $clinicAdminRole = Role::query()->where('key', RoleKey::ClinicAdmin->value)->firstOrFail();
        $receptionistRole = Role::query()->where('key', RoleKey::Receptionist->value)->firstOrFail();
        $doctorRole = Role::query()->where('key', RoleKey::Doctor->value)->firstOrFail();

        // ─── 1. Clinic + Clinic Admin ───
        $clinic = Clinic::query()->updateOrCreate(
            ['slug' => 'clinica-demo-salud'],
            [
                'name' => 'Clínica Demo Salud',
                'tax_id' => 'RUC-20612345678',
                'country_code' => 'PE',
                'contact_phone' => '+51912345678',
                'address' => 'Av. Javier Prado Este 4321, San Isidro, Lima',
                'status' => 'active',
            ],
        );

        ClinicSubscription::query()->updateOrCreate(
            ['clinic_id' => $clinic->id, 'status' => 'active'],
            [
                'plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
                'starts_at' => now(),
                'ends_at' => null,
                'provider' => 'demo',
                'provider_reference' => 'demo-clinic-sub-001',
            ],
        );

        $adminUser = User::query()->updateOrCreate(
            ['email' => 'clinica@hipocratiq.test'],
            [
                'name' => 'Carmen Vega Rodríguez',
                'phone_number' => '+51987654321',
                'country_code' => 'PE',
                'password' => $password,
                'status' => UserStatus::Active,
                'onboarding_status' => OnboardingStatus::Completed,
                'phone_verified_at' => now(),
                'last_login_at' => now(),
            ],
        );

        $adminUser->roles()->syncWithoutDetaching([$clinicAdminRole->id]);

        ClinicMembership::query()->updateOrCreate(
            ['clinic_id' => $clinic->id, 'user_id' => $adminUser->id],
            ['role_id' => $clinicAdminRole->id, 'status' => 'active'],
        );

        // ─── 2. Receptionist ───
        $receptionistUser = User::query()->updateOrCreate(
            ['email' => 'recepcion@hipocratiq.test'],
            [
                'name' => 'Lucía Méndez',
                'phone_number' => '+51987654322',
                'country_code' => 'PE',
                'password' => $password,
                'status' => UserStatus::Active,
                'onboarding_status' => OnboardingStatus::Completed,
                'phone_verified_at' => now(),
                'last_login_at' => now(),
            ],
        );

        $receptionistUser->roles()->syncWithoutDetaching([$receptionistRole->id]);

        ClinicMembership::query()->updateOrCreate(
            ['clinic_id' => $clinic->id, 'user_id' => $receptionistUser->id],
            ['role_id' => $receptionistRole->id, 'status' => 'active'],
        );

        // ─── 3. Doctor (affiliated to clinic) ───
        $doctorUser = User::query()->updateOrCreate(
            ['email' => 'medico@hipocratiq.test'],
            [
                'name' => 'Dr. Andrés Montoya Quiroz',
                'phone_number' => '+51976543210',
                'country_code' => 'PE',
                'password' => $password,
                'status' => UserStatus::Active,
                'onboarding_status' => OnboardingStatus::Completed,
                'phone_verified_at' => now(),
                'last_login_at' => now(),
            ],
        );

        $doctorUser->roles()->syncWithoutDetaching([$doctorRole->id]);

        DoctorProfile::query()->updateOrCreate(
            ['user_id' => $doctorUser->id],
            [
                'clinic_id' => $clinic->id,
                'medical_license' => 'CMP-074521',
                'specialty' => 'Cardiología',
                'practice_type' => DoctorPracticeType::Clinic,
            ],
        );

        ClinicMembership::query()->updateOrCreate(
            ['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id],
            ['role_id' => $doctorRole->id, 'status' => 'active'],
        );

        // ─── 3. Doctor (independent with own subscription) ───
        $independentDoctor = User::query()->updateOrCreate(
            ['email' => 'independiente@hipocratiq.test'],
            [
                'name' => 'Dra. Lucía Paredes Silva',
                'phone_number' => '+51965432109',
                'country_code' => 'PE',
                'password' => $password,
                'status' => UserStatus::Active,
                'onboarding_status' => OnboardingStatus::Completed,
                'phone_verified_at' => now(),
                'last_login_at' => now(),
            ],
        );

        $independentDoctor->roles()->syncWithoutDetaching([$doctorRole->id]);

        $independentProfile = DoctorProfile::query()->updateOrCreate(
            ['user_id' => $independentDoctor->id],
            [
                'clinic_id' => null,
                'medical_license' => 'CMP-089312',
                'specialty' => 'Dermatología',
                'practice_type' => DoctorPracticeType::Independent,
            ],
        );

        DoctorSubscription::query()->updateOrCreate(
            ['doctor_profile_id' => $independentProfile->id, 'status' => 'active'],
            [
                'plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
                'starts_at' => now(),
                'ends_at' => null,
                'provider' => 'demo',
                'provider_reference' => 'demo-doctor-sub-001',
            ],
        );

        // ─── 4. Patients and appointments ───
        $patientProfiles = [];
        foreach ([
            ['María López García', 'maria.lopez@hipocratiq.test', '+51954321098'],
            ['Juan Pérez Ruiz', 'juan.perez@hipocratiq.test', '+51954321099'],
            ['Ana Torres López', 'ana.torres@hipocratiq.test', '+51954321100'],
            ['Roberto Juárez Martínez', 'roberto.juarez@hipocratiq.test', '+51954321101'],
            ['Carmen Velásquez Díaz', 'carmen.velasquez@hipocratiq.test', '+51954321102'],
            ['Luis Fernández Gómez', 'luis.fernandez@hipocratiq.test', '+51954321103'],
        ] as [$name, $email, $phone]) {
            $patientUser = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'phone_number' => $phone,
                    'country_code' => 'PE',
                    'password' => $password,
                    'status' => UserStatus::Active,
                    'onboarding_status' => OnboardingStatus::Completed,
                    'phone_verified_at' => now(),
                    'last_login_at' => now(),
                ],
            );

            $patientProfiles[] = PatientProfile::query()->updateOrCreate(
                ['user_id' => $patientUser->id],
                ['clinic_id' => $clinic->id],
            );
        }

        $today = now()->startOfDay();
        foreach ([
            [0, 9, 0, 0, AppointmentStatus::Confirmed, 'Consulta'],
            [0, 9, 30, 1, AppointmentStatus::Waiting, 'Consulta'],
            [0, 10, 0, 2, AppointmentStatus::InConsultation, 'Consulta'],
            [0, 10, 30, 3, AppointmentStatus::Confirmed, 'Control'],
            [0, 11, 0, 4, AppointmentStatus::Pending, 'Consulta'],
            [0, 11, 30, 5, AppointmentStatus::NoShow, 'Consulta'],
            [1, 9, 0, 1, AppointmentStatus::Confirmed, 'Consulta'],
            [1, 10, 30, 2, AppointmentStatus::Pending, 'Control'],
            [2, 11, 0, 0, AppointmentStatus::Confirmed, 'Consulta'],
            [3, 14, 0, 4, AppointmentStatus::Confirmed, 'Control'],
            [4, 15, 30, 5, AppointmentStatus::Canceled, 'Consulta'],
        ] as [$dayOffset, $hour, $minute, $patientIndex, $status, $type]) {
            Appointment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_profile_id' => $patientProfiles[$patientIndex]->id,
                'doctor_user_id' => $doctorUser->id,
                'scheduled_at' => $today->copy()->addDays($dayOffset)->setTime($hour, $minute),
                'status' => $status,
                'appointment_type' => $type,
            ]);
        }

        // ─── 5. Super Admin (platform) ───
        $superAdmin = User::query()
            ->where(function ($query): void {
                $query
                    ->where('email', 'superadmin@hipocratiq.test')
                    ->orWhere('phone_number', '+51943210987');
            })
            ->first() ?? new User;
        $superAdmin->fill([
            'name' => 'Rafael Torres Administrador',
            'email' => 'superadmin@hipocratiq.test',
            'phone_number' => '+51943210987',
            'country_code' => 'PE',
            'password' => $password,
            'status' => UserStatus::Active,
            'onboarding_status' => OnboardingStatus::Completed,
            'phone_verified_at' => now(),
            'last_login_at' => now(),
        ])->save();

        $superAdminRole = Role::query()->where('key', RoleKey::SuperAdmin->value)->firstOrFail();
        $superAdmin->roles()->syncWithoutDetaching([$superAdminRole->id]);

        $this->command->info('Demo users created successfully. Password: '.self::PASSWORD);
        $this->command->table(
            ['Rol', 'Email', 'Teléfono', 'Nombre'],
            [
                ['Super Admin', 'superadmin@hipocratiq.test', '+51943210987', 'Rafael Torres Administrador'],
                ['Clinic Admin', 'clinica@hipocratiq.test', '+51987654321', 'Carmen Vega Rodríguez'],
                ['Recepcionista', 'recepcion@hipocratiq.test', '+51987654322', 'Lucía Méndez'],
                ['Doctor (clínica)', 'medico@hipocratiq.test', '+51976543210', 'Dr. Andrés Montoya Quiroz'],
                ['Doctor (independiente)', 'independiente@hipocratiq.test', '+51965432109', 'Dra. Lucía Paredes Silva'],
            ],
        );
    }

    /**
     * Delete every user that is a clinic admin, doctor, or patient so the demo
     * seed is deterministic. Super admins are preserved. Cascading foreign keys
     * remove the associated profiles, memberships, subscriptions, OTP
     * challenges, and authentication sessions automatically.
     */
    private function purgeExistingRoleUsers(): void
    {
        $roleUserIds = User::query()
            ->whereHas('roles', function ($query): void {
                $query->whereIn('key', [
                    RoleKey::ClinicAdmin->value,
                    RoleKey::Receptionist->value,
                    RoleKey::Doctor->value,
                ]);
            })
            ->whereDoesntHave('roles', function ($query): void {
                $query->where('key', RoleKey::SuperAdmin->value);
            })
            ->pluck('id');

        // Patients have no role row — they are identified by their profile.
        $patientUserIds = User::query()
            ->whereHas('patientProfile')
            ->whereDoesntHave('roles', function ($query): void {
                $query->where('key', RoleKey::SuperAdmin->value);
            })
            ->pluck('id');

        $userIds = $roleUserIds->merge($patientUserIds)->unique()->values();

        // Delete the demo clinic explicitly so its subscription and any dangling
        // rows are removed even if no membership references it anymore.
        // Support tickets restrict clinic deletion, so purge them first.
        $demoClinic = Clinic::query()->where('slug', 'clinica-demo-salud')->first();

        if ($demoClinic !== null) {
            SupportTicket::query()->where('clinic_id', $demoClinic->id)->delete();

            $demoClinic->delete();
        }

        $deleted = $userIds->isEmpty() ? 0 : User::query()->whereIn('id', $userIds)->delete();

        $this->command->info("Purged {$deleted} existing clinic admin / doctor / patient user(s).");
    }
}
