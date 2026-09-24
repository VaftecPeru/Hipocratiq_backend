<?php

namespace Database\Seeders;

use App\Enums\DoctorPracticeType;
use App\Enums\OnboardingStatus;
use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Enums\UserStatus;
use App\Models\Clinic;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\DoctorProfile;
use App\Models\DoctorSubscription;
use App\Models\PatientProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class HipocratiqUatAccessSeeder extends Seeder
{
    private const CLINIC_SLUG = 'hipocratiq-uat-clinic';

    /** @var array<string, array{name: string, email: string, phone: string}> */
    private const USERS = [
        'super_admin' => [
            'name' => 'Superadministrador',
            'email' => 'superadmin@hipocratiq.com',
            'phone' => '+525510000001',
        ],
        'clinic_admin' => [
            'name' => 'Administrador',
            'email' => 'admin@hipocratiq.com',
            'phone' => '+525510000002',
        ],
        'receptionist' => [
            'name' => 'Recepcionista',
            'email' => 'recepcion@hipocratiq.com',
            'phone' => '+525510000003',
        ],
        'doctor' => [
            'name' => 'Oscar Gutiérrez Rico',
            'email' => 'medico@hipocratiq.com',
            'phone' => '+525510000004',
        ],
        'independent_doctor' => [
            'name' => 'Médico Independiente',
            'email' => 'medico.independiente@hipocratiq.com',
            'phone' => '+525510000005',
        ],
        'patient' => [
            'name' => 'Paciente Principal',
            'email' => 'paciente@hipocratiq.com',
            'phone' => '+525510000006',
        ],
    ];

    public function run(): void
    {
        if (! (bool) config('uat.enabled')) {
            throw new RuntimeException('HIPOCRATIQ UAT access seeding is disabled.');
        }

        $password = config('uat.password');
        if (! is_string($password) || mb_strlen($password) < 12) {
            throw new RuntimeException('HIPOCRATIQ_UAT_ACCESS_PASSWORD must be configured with at least 12 characters.');
        }

        $this->call(DatabaseSeeder::class);

        DB::transaction(function () use ($password): void {
            $plan = Plan::query()->where('key', 'professional')->firstOrFail();
            $roles = collect([
                RoleKey::SuperAdmin,
                RoleKey::ClinicAdmin,
                RoleKey::Receptionist,
                RoleKey::Doctor,
            ])->mapWithKeys(
                fn (RoleKey $role): array => [
                    $role->value => Role::query()->firstOrCreate(
                        ['key' => $role->value],
                        [
                            'name' => match ($role) {
                                RoleKey::SuperAdmin => 'Super Administrador',
                                RoleKey::ClinicAdmin => 'Administrador Clínico',
                                RoleKey::Receptionist => 'Recepcionista',
                                RoleKey::Doctor => 'Médico',
                            },
                            'scope' => $role === RoleKey::SuperAdmin ? RoleScope::Global : RoleScope::Organization,
                            'is_system' => true,
                        ],
                    ),
                ],
            );

            $clinic = Clinic::query()->updateOrCreate(
                ['slug' => self::CLINIC_SLUG],
                [
                    'name' => 'Clínica HIPOCRATIQ UAT',
                    'tax_id' => 'UAT-HQ-MX-001',
                    'country_code' => 'MX',
                    'contact_phone' => '+525510009999',
                    'address' => 'Entorno de pruebas HIPOCRATIQ, México',
                    'status' => 'active',
                ],
            );

            ClinicSubscription::query()->updateOrCreate(
                ['provider_reference' => 'uat-clinic-professional'],
                [
                    'clinic_id' => $clinic->id,
                    'plan_id' => $plan->id,
                    'billing_cycle' => 'monthly',
                    'status' => 'active',
                    'auto_renew' => false,
                    'starts_at' => now()->subDay(),
                    'ends_at' => null,
                    'provider' => 'uat',
                ],
            );

            $superAdmin = $this->upsertUser(self::USERS['super_admin'], $password);
            $superAdmin->roles()->syncWithoutDetaching([$roles[RoleKey::SuperAdmin->value]->id]);

            $clinicAdmin = $this->upsertUser(self::USERS['clinic_admin'], $password);
            $clinicAdmin->roles()->syncWithoutDetaching([$roles[RoleKey::ClinicAdmin->value]->id]);
            $this->upsertMembership($clinic, $clinicAdmin, $roles[RoleKey::ClinicAdmin->value]);

            $receptionist = $this->upsertUser(self::USERS['receptionist'], $password);
            $receptionist->roles()->syncWithoutDetaching([$roles[RoleKey::Receptionist->value]->id]);
            $this->upsertMembership($clinic, $receptionist, $roles[RoleKey::Receptionist->value]);

            $clinicDoctor = $this->upsertUser(self::USERS['doctor'], $password);
            $clinicDoctor->roles()->syncWithoutDetaching([$roles[RoleKey::Doctor->value]->id]);
            $this->upsertMembership($clinic, $clinicDoctor, $roles[RoleKey::Doctor->value]);
            DoctorProfile::query()->updateOrCreate(
                ['user_id' => $clinicDoctor->id],
                [
                    'clinic_id' => $clinic->id,
                    'medical_license' => '90000001',
                    'medical_license_country_code' => 'MX',
                    'medical_license_issuer' => 'UAT-SYNTHETIC',
                    'medical_license_verification_status' => 'unverified',
                    'specialty' => 'Medicina general',
                    'practice_type' => DoctorPracticeType::Clinic,
                ],
            );

            $independentDoctor = $this->upsertUser(self::USERS['independent_doctor'], $password);
            $independentDoctor->roles()->syncWithoutDetaching([$roles[RoleKey::Doctor->value]->id]);
            $independentProfile = DoctorProfile::query()->updateOrCreate(
                ['user_id' => $independentDoctor->id],
                [
                    'clinic_id' => null,
                    'medical_license' => '90000002',
                    'medical_license_country_code' => 'MX',
                    'medical_license_issuer' => 'UAT-SYNTHETIC',
                    'medical_license_verification_status' => 'unverified',
                    'specialty' => 'Medicina general',
                    'practice_type' => DoctorPracticeType::Independent,
                ],
            );
            DoctorSubscription::query()->updateOrCreate(
                ['provider_reference' => 'uat-independent-doctor-professional'],
                [
                    'doctor_profile_id' => $independentProfile->id,
                    'plan_id' => $plan->id,
                    'billing_cycle' => 'monthly',
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'ends_at' => null,
                    'provider' => 'uat',
                ],
            );

            $patient = $this->upsertUser(self::USERS['patient'], $password);
            PatientProfile::query()->updateOrCreate(
                ['user_id' => $patient->id],
                [
                    'clinic_id' => $clinic->id,
                    'document_type' => 'UAT-ID',
                    'document_number' => 'UAT-PACIENTE-001',
                    'whatsapp_consent' => true,
                ],
            );
        });

        $this->command?->info('HIPOCRATIQ UAT role access accounts are ready.');
    }

    /** @param array{name: string, email: string, phone: string} $attributes */
    private function upsertUser(array $attributes, string $password): User
    {
        $this->assertIdentifiersAvailable($attributes['email'], $attributes['phone']);

        $user = User::query()->firstOrNew(['email' => $attributes['email']]);
        $user->fill([
            'name' => $attributes['name'],
            'phone_number' => $attributes['phone'],
            'country_code' => 'MX',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'password' => Hash::make($password),
            'status' => UserStatus::Active,
            'onboarding_status' => OnboardingStatus::Completed,
        ]);
        $user->save();

        return $user;
    }

    private function assertIdentifiersAvailable(string $email, string $phone): void
    {
        $emailOwner = User::query()->where('email', $email)->first();
        if ($emailOwner !== null && $emailOwner->email !== $email) {
            throw new RuntimeException('UAT email collision detected.');
        }

        $phoneCollision = User::query()
            ->where('phone_number', $phone)
            ->where('email', '<>', $email)
            ->exists();

        if ($phoneCollision) {
            throw new RuntimeException('UAT phone collision detected.');
        }
    }

    private function upsertMembership(Clinic $clinic, User $user, Role $role): void
    {
        ClinicMembership::query()->updateOrCreate(
            ['clinic_id' => $clinic->id, 'user_id' => $user->id],
            ['role_id' => $role->id, 'status' => 'active'],
        );
    }
}
