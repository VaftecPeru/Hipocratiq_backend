<?php

namespace Database\Factories;

use App\Enums\OnboardingStatus;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => fake()->unique()->numerify('+5255########'),
            'country_code' => 'MX',
            'phone_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'onboarding_status' => OnboardingStatus::Completed,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the phone number should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone_verified_at' => null,
            'status' => UserStatus::PendingVerification,
            'onboarding_status' => OnboardingStatus::PhoneVerification,
        ]);
    }
}
