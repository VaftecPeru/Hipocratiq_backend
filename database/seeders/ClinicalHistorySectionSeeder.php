<?php

namespace Database\Seeders;

use App\Models\ClinicalHistorySection;
use Illuminate\Database\Seeder;

class ClinicalHistorySectionSeeder extends Seeder
{
    /**
     * Seed the clinical history section catalog idempotently.
     *
     * The content_schema describes the field intent of each section from the
     * source clinical history templates. It carries NO patient-identifying
     * sample values; it is structural metadata only.
     */
    public function run(): void
    {
        foreach ($this->sections() as $section) {
            ClinicalHistorySection::query()->updateOrCreate(
                ['key' => $section['key']],
                $section,
            );
        }
    }

    /** @return array<int, array{key: string, title: string, description: string, sort_order: int, content_schema: array<string, mixed>, is_active: bool}> */
    private function sections(): array
    {
        return [
            [
                'key' => 'record_metadata',
                'title' => 'Datos de la institución y del expediente',
                'description' => 'Unidad médica, número de expediente, fecha/hora de elaboración y tipo de interrogatorio.',
                'sort_order' => 1,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'unit', 'label' => 'Unidad médica', 'type' => 'text'],
                        ['key' => 'record_number', 'label' => 'No. de expediente', 'type' => 'text'],
                        ['key' => 'date', 'label' => 'Fecha de elaboración', 'type' => 'date'],
                        ['key' => 'time', 'label' => 'Hora de elaboración', 'type' => 'time'],
                        ['key' => 'interview_type', 'label' => 'Tipo de interrogatorio', 'type' => 'select', 'options' => ['directo', 'indirecto']],
                    ],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'patient_identification',
                'title' => 'Ficha de identificación',
                'description' => 'Datos generales y de identificación del paciente.',
                'sort_order' => 2,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'name', 'label' => 'Nombre del paciente', 'type' => 'text'],
                        ['key' => 'age', 'label' => 'Edad', 'type' => 'number'],
                        ['key' => 'gender', 'label' => 'Género', 'type' => 'select', 'options' => ['femenino', 'masculino', 'otro']],
                        ['key' => 'birth_date', 'label' => 'Fecha de nacimiento', 'type' => 'date'],
                        ['key' => 'occupation', 'label' => 'Ocupación', 'type' => 'text'],
                        ['key' => 'address', 'label' => 'Domicilio', 'type' => 'text'],
                        ['key' => 'guardian_name', 'label' => 'Nombre del padre o tutor', 'type' => 'text'],
                        ['key' => 'relationship', 'label' => 'Parentesco', 'type' => 'text'],
                    ],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'family_history',
                'title' => 'Antecedentes heredo-familiares',
                'description' => 'Antecedentes de enfermedad en familiares directos.',
                'sort_order' => 3,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'description', 'label' => 'Antecedentes familiares', 'type' => 'textarea'],
                    ],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'non_pathological_history',
                'title' => 'Antecedentes personales no patológicos',
                'description' => 'Hábitos, vivienda, escolaridad, alimentación y otros antecedentes no patológicos.',
                'sort_order' => 4,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'description', 'label' => 'Antecedentes no patológicos', 'type' => 'textarea'],
                    ],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'pathological_history',
                'title' => 'Antecedentes personales patológicos',
                'description' => 'Enfermedades, alergias, transfusiones, traumatismos y cirugías previas.',
                'sort_order' => 5,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'description', 'label' => 'Antecedentes patológicos', 'type' => 'textarea'],
                    ],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'gyneco_obstetric_history',
                'title' => 'Antecedentes gineco-obstétricos',
                'description' => 'Antecedentes ginecológicos y obstétricos del paciente.',
                'sort_order' => 6,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'description', 'label' => 'Antecedentes gineco-obstétricos', 'type' => 'textarea'],
                    ],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'present_illness',
                'title' => 'Padecimiento actual',
                'description' => 'Motivo de consulta y narrativa del padecimiento actual.',
                'sort_order' => 7,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'reason', 'label' => 'Motivo de consulta', 'type' => 'text'],
                        ['key' => 'illness_narrative', 'label' => 'Enfermedad actual', 'type' => 'textarea'],
                    ],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'systems_review',
                'title' => 'Interrogatorio por aparatos y sistemas',
                'description' => 'Revisión por sistemas: cardiovascular, respiratorio, gastrointestinal, genitourinario, hematológico, endocrino, nervioso, musculoesquelético y piel.',
                'sort_order' => 8,
                'content_schema' => [
                    'systems' => [
                        ['key' => 'cardiovascular', 'label' => 'Cardiovascular'],
                        ['key' => 'respiratory', 'label' => 'Respiratorio'],
                        ['key' => 'gastrointestinal', 'label' => 'Gastrointestinal'],
                        ['key' => 'genitourinary', 'label' => 'Genitourinario'],
                        ['key' => 'hematologic_lymphatic', 'label' => 'Hemático y linfático'],
                        ['key' => 'endocrine', 'label' => 'Endocrino'],
                        ['key' => 'nervous', 'label' => 'Nervioso'],
                        ['key' => 'musculoskeletal', 'label' => 'Musculoesquelético'],
                        ['key' => 'skin_mucosa_appendages', 'label' => 'Piel, mucosas y anexos'],
                    ],
                    'field_type' => 'textarea',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'vital_signs',
                'title' => 'Signos vitales',
                'description' => 'Tensión arterial, temperatura, frecuencia cardíaca, frecuencia respiratoria, peso y talla.',
                'sort_order' => 9,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'blood_pressure', 'label' => 'T/A', 'type' => 'text'],
                        ['key' => 'temperature', 'label' => 'Temperatura', 'type' => 'text'],
                        ['key' => 'heart_rate', 'label' => 'F.C.', 'type' => 'text'],
                        ['key' => 'respiratory_rate', 'label' => 'F.R.', 'type' => 'text'],
                        ['key' => 'weight', 'label' => 'Peso', 'type' => 'text'],
                        ['key' => 'height', 'label' => 'Talla', 'type' => 'text'],
                    ],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'physical_examination',
                'title' => 'Exploración física',
                'description' => 'Exploración por regiones: hábito exterior, cabeza, cuello, tórax, abdomen, genitales, extremidades y piel.',
                'sort_order' => 10,
                'content_schema' => [
                    'regions' => [
                        ['key' => 'habitus_exterior', 'label' => 'Hábito exterior'],
                        ['key' => 'head', 'label' => 'Cabeza'],
                        ['key' => 'neck', 'label' => 'Cuello'],
                        ['key' => 'thorax', 'label' => 'Tórax'],
                        ['key' => 'abdomen', 'label' => 'Abdomen'],
                        ['key' => 'genitalia', 'label' => 'Genitales'],
                        ['key' => 'extremities', 'label' => 'Extremidades'],
                        ['key' => 'skin', 'label' => 'Piel'],
                    ],
                    'field_type' => 'textarea',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'labs_imaging',
                'title' => 'Resultados de laboratorio y gabinete',
                'description' => 'Resultados previos y actuales de laboratorio, gabinete y otros estudios.',
                'sort_order' => 11,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'results', 'label' => 'Resultados de laboratorio y gabinete', 'type' => 'textarea'],
                    ],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'diagnoses_problems',
                'title' => 'Diagnóstico o problemas clínicos',
                'description' => 'Diagnósticos o problemas clínicos identificados.',
                'sort_order' => 12,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'diagnoses', 'label' => 'Diagnóstico(s) o problema(s) clínico(s)', 'type' => 'textarea'],
                    ],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'pharmacological_treatment',
                'title' => 'Tratamiento farmacológico',
                'description' => 'Tratamiento farmacológico e indicaciones generales.',
                'sort_order' => 13,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'treatment', 'label' => 'Tratamiento farmacológico', 'type' => 'textarea'],
                    ],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'prognosis',
                'title' => 'Pronóstico',
                'description' => 'Pronóstico clínico del paciente.',
                'sort_order' => 14,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'prognosis', 'label' => 'Pronóstico', 'type' => 'textarea'],
                    ],
                ],
                'is_active' => true,
            ],
            [
                'key' => 'clinician_identification',
                'title' => 'Identificación del clínico',
                'description' => 'Identificación y firma del clínico responsable.',
                'sort_order' => 15,
                'content_schema' => [
                    'fields' => [
                        ['key' => 'clinician_name', 'label' => 'Nombre del clínico', 'type' => 'text'],
                        ['key' => 'title', 'label' => 'Título / cédula profesional', 'type' => 'text'],
                        ['key' => 'unit', 'label' => 'Unidad de adscripción', 'type' => 'text'],
                    ],
                ],
                'is_active' => true,
            ],
        ];
    }
}
