<?php

namespace Tests\Feature;

use App\Models\Specialty;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpecialtyCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_returns_the_exact_active_specialties_and_parented_subspecialties(): void
    {
        $this->seed(SpecialtySeeder::class);
        $this->seed(SpecialtySeeder::class);

        $this->assertDatabaseCount('specialties', 41);
        $expectedSpecialties = [
            'Anatomía Patológica',
            'Anestesiología',
            'Audiología, Otoneurología y Foniatría',
            'Calidad de la Atención Clínica',
            'Cirugía General',
            'Epidemiología',
            'Genética Médica',
            'Geriatría',
            'Ginecología y Obstetricia',
            'Imagenología Diagnóstica y Terapéutica',
            'Medicina de la Actividad Física y Deportiva',
            'Medicina de Rehabilitación',
            'Medicina de Urgencias',
            'Medicina del Trabajo y Ambiental',
            'Medicina Familiar',
            'Medicina Interna',
            'Medicina Nuclear e Imagenología Molecular',
            'Medicina Paliativa',
            'Medicina Preventiva',
            'Neumología',
            'Oftalmología',
            'Otorrinolaringología y Cirugía de Cabeza y Cuello',
            'Patología Clínica',
            'Pediatría',
            'Psiquiatría',
            'Radio Oncología',
            'Traumatología y Ortopedia',
        ];
        $actualSpecialties = Specialty::query()->whereNull('parent_id')->pluck('name')->all();
        sort($expectedSpecialties);
        sort($actualSpecialties);
        $this->assertSame($expectedSpecialties, $actualSpecialties);
        $this->getJson('/api/v1/auth/register/doctor/specialties')
            ->assertOk()
            ->assertJsonCount(27, 'data')
            ->assertJsonFragment(['name' => 'Medicina Interna'])
            ->assertJsonFragment(['name' => 'Cardiología'])
            ->assertJsonFragment(['name' => 'Medicina Materno-Fetal']);

        $internalMedicine = Specialty::query()->where('code', 'medicina_interna')->sole();
        $this->assertSame($internalMedicine->id, Specialty::query()->where('code', 'cardiologia')->sole()->parent_id);
    }
}
