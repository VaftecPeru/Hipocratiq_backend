<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\ClinicalNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClinicalNotification>
 */
class ClinicalNotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'user_id' => User::factory(),
            'type' => 'emergency',
            'event_key' => 'emergency_activated',
            'source_type' => 'factory',
            'source_id' => fake()->unique()->uuid(),
            'title_key' => 'clinicalNotifications.emergency.activatedTitle',
            'message_key' => 'clinicalNotifications.emergency.activatedMessage',
            'severity' => 'critical',
            'href' => '/clinic/configuracion/emergencia',
            'payload' => ['clinic_name' => fake()->company().' Clínica'],
            'occurred_at' => now(),
        ];
    }
}
