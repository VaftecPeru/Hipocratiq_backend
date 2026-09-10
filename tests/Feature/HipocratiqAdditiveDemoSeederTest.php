<?php

namespace Tests\Feature;

use Database\Seeders\HipocratiqAdditiveDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HipocratiqAdditiveDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_demo_seeder_is_idempotent_for_demo_identifiers_and_catalogs(): void
    {
        $this->seed(HipocratiqAdditiveDemoSeeder::class);
        $this->seed(HipocratiqAdditiveDemoSeeder::class);

        $demoEmails = [
            'super@test.local',
            'clinic@test.local',
            'recep@test.local',
            'doctor@test.local',
            'inddoc@test.local',
            'pat1@test.local',
            'pat2@test.local',
            'pat3@test.local',
            'pat4@test.local',
            'pat5@test.local',
            'indpat@test.local',
        ];
        $demoUsers = DB::table('users')->whereIn('email', $demoEmails);
        $demoAppointments = DB::table('appointments')->where('notes', 'like', 'hipocratiq-additive-demo:%');

        $this->assertSame(4, DB::table('roles')->whereIn('key', [
            'super_admin',
            'clinic_admin',
            'doctor',
            'receptionist',
        ])->distinct()->count('key'));
        $this->assertSame(3, DB::table('plans')->whereIn('key', ['basic', 'professional', 'enterprise'])->distinct()->count('key'));
        $this->assertSame(3, DB::table('specialties')->whereIn('code', [
            'medicina_interna',
            'cardiologia',
            'dermatologia',
        ])->distinct()->count('code'));
        $this->assertSame(15, DB::table('clinical_history_sections')->where('is_active', true)->distinct()->count('key'));

        $this->assertSame(1, DB::table('clinics')->where('slug', 'hipocratiq-additive-demo')->count());
        $this->assertSame(1, DB::table('clinics')->where('tax_id', 'DEMO-HIPOCRATIQ-ADDITIVE-001')->count());
        $this->assertSame(11, $demoUsers->count());
        $this->assertSame(11, DB::table('users')
            ->whereIn('email', $demoEmails)
            ->distinct()
            ->count('email'));
        $this->assertSame(1, DB::table('clinic_subscriptions')->where('provider_reference', 'demo-additive-clinic-subscription-001')->count());
        $this->assertSame(1, DB::table('doctor_subscriptions')->where('provider_reference', 'demo-additive-doctor-subscription-001')->count());
        $this->assertSame(1, DB::table('subscription_payments')->where('idempotency_key', 'demo-additive-payment-001')->count());
        $this->assertSame(1, DB::table('subscription_payments')->where('provider_reference', 'demo-additive-payment-001')->count());
        $this->assertSame(2, DB::table('doctor_profiles')->where('medical_license', 'like', 'DEMO-CMP-%')->distinct()->count('medical_license'));
        $this->assertSame(6, DB::table('patient_profiles')->where('document_type', 'DEMO-ID')->count());
        $this->assertSame(12, $demoAppointments->count());
        $this->assertSame(12, DB::table('appointments')
            ->where('notes', 'like', 'hipocratiq-additive-demo:%')
            ->distinct()
            ->count('notes'));
        $this->assertSame(3, DB::table('patient_intakes')->where('objective', 'like', 'Synthetic demo intake%')->count());
        $this->assertSame(2, DB::table('clinical_history_records')->where('source', 'demo_additive')->count());
        $this->assertSame(30, DB::table('clinical_history_entries')->count());
        $this->assertSame(2, DB::table('consultations')->count());
    }
}
