<?php

namespace Database\Seeders;

use App\Models\Specialty;
use Illuminate\Database\Seeder;

class SpecialtySeeder extends Seeder
{
    public function run(): void
    {
        $specialties = [
            'anatomia_patologica' => 'Anatomía Patológica',
            'anestesiologia' => 'Anestesiología',
            'audiologia_otoneurologia_y_foniatria' => 'Audiología, Otoneurología y Foniatría',
            'calidad_de_la_atencion_clinica' => 'Calidad de la Atención Clínica',
            'cirugia_general' => 'Cirugía General',
            'epidemiologia' => 'Epidemiología',
            'genetica_medica' => 'Genética Médica',
            'geriatria' => 'Geriatría',
            'ginecologia_y_obstetricia' => 'Ginecología y Obstetricia',
            'imagenologia_diagnostica_y_terapeutica' => 'Imagenología Diagnóstica y Terapéutica',
            'medicina_de_la_actividad_fisica_y_deportiva' => 'Medicina de la Actividad Física y Deportiva',
            'medicina_de_rehabilitacion' => 'Medicina de Rehabilitación',
            'medicina_de_urgencias' => 'Medicina de Urgencias',
            'medicina_del_trabajo_y_ambiental' => 'Medicina del Trabajo y Ambiental',
            'medicina_familiar' => 'Medicina Familiar',
            'medicina_interna' => 'Medicina Interna',
            'medicina_nuclear_e_imagenologia_molecular' => 'Medicina Nuclear e Imagenología Molecular',
            'medicina_paliativa' => 'Medicina Paliativa',
            'medicina_preventiva' => 'Medicina Preventiva',
            'neumologia' => 'Neumología',
            'oftalmologia' => 'Oftalmología',
            'otorrinolaringologia_y_cirugia_de_cabeza_y_cuello' => 'Otorrinolaringología y Cirugía de Cabeza y Cuello',
            'patologia_clinica' => 'Patología Clínica',
            'pediatria' => 'Pediatría',
            'psiquiatria' => 'Psiquiatría',
            'radio_oncologia' => 'Radio Oncología',
            'traumatologia_y_ortopedia' => 'Traumatología y Ortopedia',
        ];

        foreach ($specialties as $code => $name) {
            Specialty::query()->updateOrCreate(['code' => $code], ['name' => $name, 'parent_id' => null, 'is_active' => true]);
        }

        $subspecialties = [
            'medicina_interna' => ['cardiologia' => 'Cardiología', 'neurologia' => 'Neurología', 'dermatologia' => 'Dermatología', 'nefrologia' => 'Nefrología', 'endocrinologia' => 'Endocrinología', 'gastroenterologia' => 'Gastroenterología', 'reumatologia' => 'Reumatología'],
            'pediatria' => ['neonatologia' => 'Neonatología', 'neurologia_pediatrica' => 'Neurología Pediátrica', 'cardiologia_pediatrica' => 'Cardiología Pediátrica'],
            'cirugia_general' => ['cirugia_oncologica' => 'Cirugía Oncológica', 'cirugia_vascular' => 'Cirugía Vascular', 'coloproctologia' => 'Coloproctología'],
            'ginecologia_y_obstetricia' => ['medicina_materno_fetal' => 'Medicina Materno-Fetal'],
        ];

        foreach ($subspecialties as $parentCode => $children) {
            $parent = Specialty::query()->where('code', $parentCode)->sole();
            foreach ($children as $code => $name) {
                Specialty::query()->updateOrCreate(['code' => $code], ['name' => $name, 'parent_id' => $parent->id, 'is_active' => true]);
            }
        }
    }
}
