<?php

namespace App\Enums;

enum ClinicalCodingCategory: string
{
    case Diagnosis = 'diagnosis';
    case Problem = 'problem';
    case Symptom = 'symptom';
    case Allergy = 'allergy';
    case Observation = 'observation';
    case Procedure = 'procedure';
    case Medication = 'medication';
}
