<?php

namespace Database\Factories;

use App\Models\ClinicalHistoryEntry;
use App\Models\ClinicalHistoryRecord;
use App\Models\ClinicalHistorySection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalHistoryEntry>
 */
class ClinicalHistoryEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinical_history_record_id' => ClinicalHistoryRecord::factory(),
            'clinical_history_section_id' => ClinicalHistorySection::factory(),
            'values' => ['description' => fake()->sentence()],
        ];
    }
}
