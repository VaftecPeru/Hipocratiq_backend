<?php

namespace Database\Seeders;

use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Plan;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Role::query()->firstOrCreate(
            ['key' => RoleKey::SuperAdmin->value],
            [
                'name' => 'Super Administrador',
                'scope' => RoleScope::Global,
                'is_system' => true,
            ],
        );

        Role::query()->firstOrCreate(
            ['key' => RoleKey::ClinicAdmin->value],
            [
                'name' => 'Administrador Clínico',
                'scope' => RoleScope::Organization,
                'is_system' => true,
            ],
        );

        Role::query()->firstOrCreate(
            ['key' => RoleKey::Doctor->value],
            [
                'name' => 'Médico',
                'scope' => RoleScope::Organization,
                'is_system' => true,
            ],
        );

        foreach ([
            ['key' => 'basic', 'name' => 'Básico', 'monthly_price_cents' => 4900, 'annual_price_cents' => 47000, 'features' => ['Hasta 3 especialistas', 'Gestión de pacientes ilimitada', 'Citas y calendario básico', 'Soporte vía email']],
            ['key' => 'professional', 'name' => 'Profesional', 'monthly_price_cents' => 9900, 'annual_price_cents' => 95000, 'features' => ['Hasta 15 especialistas', 'Reportes avanzados y analítica', 'Receta electrónica', 'Soporte prioritario 24/7']],
            ['key' => 'enterprise', 'name' => 'Enterprise', 'monthly_price_cents' => 0, 'annual_price_cents' => 0, 'features' => ['Especialistas ilimitados', 'Infraestructura dedicada', 'API personalizada', 'Account Manager dedicado'], 'publicly_selectable' => false],
        ] as $plan) {
            Plan::query()->updateOrCreate(
                ['key' => $plan['key']],
                $plan + ['currency' => 'USD', 'is_active' => true, 'publicly_selectable' => $plan['publicly_selectable'] ?? true],
            );
        }
    }
}
