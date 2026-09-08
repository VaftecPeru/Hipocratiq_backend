<?php

namespace Tests\Feature;

use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Clinic;
use App\Models\ClinicalNotification;
use App\Models\ClinicMembership;
use App\Models\ClinicSubscription;
use App\Models\DoctorProfile;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class ClinicalNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_global_emergency_publication_fanouts_by_visibility_and_is_idempotent(): void
    {
        $clinic = $this->createClinic();
        $admin = $this->createMember($clinic, RoleKey::ClinicAdmin, '+525510000001');
        $doctor = $this->createMember($clinic, RoleKey::Doctor, '+525510000002');
        $receptionist = $this->createMember($clinic, RoleKey::Receptionist, '+525510000003');
        $this->createClinic();
        $this->authenticateAdmin();

        $this->withSpaOrigin()->patchJson('/api/v1/auth/admin/emergency', [
            'enabled' => true,
            'visibility' => 'doctors_admins',
            'label' => 'Global emergency',
            'color' => '#EF2F3F',
            'inheritance' => 'workflow_and_responsibles',
            'default_channel' => 'priority_push',
            'escalation_minutes' => 3,
            'responsibles' => [['role' => 'Clinical admin', 'channel' => 'In-app']],
        ])->assertOk();

        $payload = [
            'scope' => 'all',
            'force' => true,
            'note' => 'Global emergency test',
            'idempotency_key' => 'global-emergency-test-1',
        ];

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/emergency/publish', $payload)->assertOk();
        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/emergency/publish', $payload)->assertOk();

        $this->assertDatabaseCount('clinical_notifications', 2);
        $this->assertDatabaseHas('clinical_notifications', [
            'clinic_id' => $clinic->id,
            'user_id' => $admin->id,
            'event_key' => 'emergency_published',
        ]);
        $this->assertDatabaseHas('clinical_notifications', [
            'clinic_id' => $clinic->id,
            'user_id' => $doctor->id,
            'event_key' => 'emergency_published',
        ]);
        $this->assertDatabaseMissing('clinical_notifications', [
            'clinic_id' => $clinic->id,
            'user_id' => $receptionist->id,
        ]);
    }

    public function test_clinical_notification_api_is_scoped_to_the_authenticated_clinic(): void
    {
        $clinic = $this->createClinic();
        $foreignClinic = $this->createClinic();
        $admin = $this->createMember($clinic, RoleKey::ClinicAdmin, '+525510000011');
        $foreignAdmin = $this->createMember($foreignClinic, RoleKey::ClinicAdmin, '+525510000012');
        $notification = $this->createNotification($clinic, $admin);
        $foreignNotification = $this->createNotification($foreignClinic, $foreignAdmin, 'foreign-source');

        $this->authenticate($admin, '55 1000 0011', RoleKey::ClinicAdmin);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinical/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'clinical:'.$notification->id)
            ->assertJsonPath('meta.unread_count', 1);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/clinical/notifications/read', [
                'notification_ids' => [
                    'clinical:'.$notification->id,
                    'clinical:'.$foreignNotification->id,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.marked_count', 1)
            ->assertJsonPath('meta.unread_count', 0);

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull($foreignNotification->fresh()->read_at);
    }

    public function test_local_emergency_activation_creates_one_persistent_notification(): void
    {
        $clinic = $this->createClinic([
            'preferences' => [
                'emergency' => ['enabled' => false],
            ],
        ]);
        $admin = $this->createMember($clinic, RoleKey::ClinicAdmin, '+525510002001');
        $this->authenticate($admin, '55 1000 2001', RoleKey::ClinicAdmin);

        $settings = [
            'preferences' => [
                'emergency' => [
                    'enabled' => true,
                    'visibility' => 'admins_only',
                ],
            ],
        ];

        $this->withSpaOrigin()->patchJson('/api/v1/auth/clinic/settings', $settings)->assertOk();
        $this->withSpaOrigin()->patchJson('/api/v1/auth/clinic/settings', $settings)->assertOk();

        $this->assertDatabaseCount('clinical_notifications', 1);
        $this->assertDatabaseHas('clinical_notifications', [
            'clinic_id' => $clinic->id,
            'user_id' => $admin->id,
            'event_key' => 'emergency_activated',
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'module' => 'emergency',
            'action' => 'emergency_activated',
            'clinic_id' => $clinic->id,
            'is_critical' => true,
        ]);
    }

    public function test_clinic_doctor_can_read_notifications_for_their_clinic(): void
    {
        $clinic = $this->createClinic();
        $doctor = $this->createMember($clinic, RoleKey::Doctor, '+525510003001');
        DoctorProfile::factory()->create([
            'user_id' => $doctor->id,
            'clinic_id' => $clinic->id,
            'practice_type' => 'clinic',
        ]);
        $notification = $this->createNotification($clinic, $doctor);

        $this->authenticate($doctor, '55 1000 3001', RoleKey::Doctor);

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/clinical/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'clinical:'.$notification->id)
            ->assertJsonPath('data.0.clinic.id', $clinic->id);
    }

    private function createClinic(array $attributes = []): Clinic
    {
        $clinic = Clinic::factory()->create($attributes);
        ClinicSubscription::factory()->create([
            'clinic_id' => $clinic->id,
            'plan_id' => Plan::query()->value('id') ?? Plan::factory(),
        ]);

        return $clinic;
    }

    private function createMember(Clinic $clinic, RoleKey $roleKey, string $phone): User
    {
        $role = Role::query()->firstOrCreate(
            ['key' => $roleKey->value],
            ['name' => ucfirst(str_replace('_', ' ', $roleKey->value)), 'scope' => RoleScope::Organization, 'is_system' => true],
        );
        $user = User::factory()->create(['phone_number' => $phone, 'password' => 'SecurePass123']);
        $user->roles()->syncWithoutDetaching([$role->id]);
        ClinicMembership::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);

        return $user;
    }

    private function createNotification(Clinic $clinic, User $user, string $sourceId = 'source-1'): ClinicalNotification
    {
        return ClinicalNotification::query()->create([
            'clinic_id' => $clinic->id,
            'user_id' => $user->id,
            'type' => 'emergency',
            'event_key' => 'emergency_activated',
            'source_type' => 'test',
            'source_id' => $sourceId,
            'title_key' => 'clinicalNotifications.emergency.activatedTitle',
            'message_key' => 'clinicalNotifications.emergency.activatedMessage',
            'severity' => 'critical',
            'href' => '/clinic/configuracion/emergencia',
            'payload' => ['clinic_name' => $clinic->name],
            'occurred_at' => now(),
        ]);
    }

    private function authenticateAdmin(): User
    {
        $admin = User::factory()->superAdmin()->create([
            'email' => 'clinical-notifications-admin@example.test',
            'phone_number' => '+525510000099',
            'password' => 'SecurePass123',
        ]);
        $this->authenticate($admin, '55 1000 0099', RoleKey::SuperAdmin);

        return $admin;
    }

    private function authenticate(User $user, string $phone, RoleKey $roleKey): void
    {
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => $phone,
            'password' => 'SecurePass123',
            'requested_role' => $roleKey->value,
        ])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk();
        $sessionCookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
