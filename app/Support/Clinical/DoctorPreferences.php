<?php

namespace App\Support\Clinical;

final class DoctorPreferences
{
    /** @var list<string> */
    public const array Weekdays = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'working_hours' => [
                'monday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'tuesday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'wednesday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'thursday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'friday' => ['enabled' => true, 'start' => '08:00', 'end' => '18:00'],
                'saturday' => ['enabled' => false, 'start' => '09:00', 'end' => '13:00'],
                'sunday' => ['enabled' => false, 'start' => '09:00', 'end' => '13:00'],
            ],
            'appointment_duration_minutes' => 30,
            'buffer_minutes' => 0,
            'teleconsultations_enabled' => true,
            'auto_confirm_appointments' => false,
            'notifications' => [
                'results_pending' => true,
                'new_messages' => true,
                'follow_up_reminders' => true,
                'epidemiology_alerts' => true,
                'upcoming_teleconsultations' => true,
            ],
            'clinical' => [
                'show_relevant_history' => true,
                'show_epidemiology' => true,
            ],
        ];
    }

    /** @param array<string, mixed>|null $stored @return array<string, mixed> */
    public static function resolve(?array $stored): array
    {
        return array_replace_recursive(self::defaults(), $stored ?? []);
    }
}
