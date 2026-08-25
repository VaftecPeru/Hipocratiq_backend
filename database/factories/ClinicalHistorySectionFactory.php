<?php

namespace Database\Factories;

use App\Models\ClinicalHistorySection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalHistorySection>
 */
class ClinicalHistorySectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(3),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'sort_order' => fake()->numberBetween(1, 15),
            'content_schema' => ['fields' => []],
            'is_active' => true,
        ];
    }
}
