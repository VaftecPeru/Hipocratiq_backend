<?php

namespace Database\Factories;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Models\Clinic;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => fake()->unique()->numerify('TK-######'),
            'clinic_id' => Clinic::factory(),
            'requested_by_user_id' => User::factory(),
            'assigned_to_user_id' => null,
            'category' => SupportTicketCategory::Technical,
            'priority' => SupportTicketPriority::Normal,
            'status' => SupportTicketStatus::Open,
            'subject' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'contact_email' => fake()->safeEmail(),
            'notify_on_create' => true,
        ];
    }
}
