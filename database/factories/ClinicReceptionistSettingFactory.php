<?php

namespace Database\Factories;

use App\Models\Clinic;
use App\Models\ClinicReceptionistSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClinicReceptionistSetting> */
class ClinicReceptionistSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'clinic_id' => Clinic::factory(),
            'user_id' => User::factory(),
            'preferences' => [
                'new_appointments' => true,
                'arrivals' => true,
                'pending_payments' => true,
                'internal_messages' => true,
                'documents' => true,
                'online_reception' => true,
                'whatsapp' => true,
                'auto_print' => true,
                'duplicate_tickets' => false,
                'shift_name' => null,
                'shift_start' => null,
                'shift_end' => null,
                'counter_name' => null,
                'printer_name' => null,
                'paper_size' => null,
            ],
        ];
    }
}
