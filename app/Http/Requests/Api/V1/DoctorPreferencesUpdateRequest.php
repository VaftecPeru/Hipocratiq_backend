<?php

namespace App\Http\Requests\Api\V1;

use App\Support\Clinical\DoctorPreferences;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DoctorPreferencesUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $days = implode(',', DoctorPreferences::Weekdays);

        return [
            'preferences' => ['required', 'array:working_hours,appointment_duration_minutes,buffer_minutes,teleconsultations_enabled,auto_confirm_appointments,notifications,clinical'],
            'preferences.working_hours' => ["sometimes", "array:{$days}"],
            'preferences.working_hours.*' => ['sometimes', 'array:enabled,start,end'],
            'preferences.working_hours.*.enabled' => ['sometimes', 'boolean'],
            'preferences.working_hours.*.start' => ['sometimes', 'date_format:H:i'],
            'preferences.working_hours.*.end' => ['sometimes', 'date_format:H:i'],
            'preferences.appointment_duration_minutes' => ['sometimes', 'integer', 'in:15,30,45,60,90'],
            'preferences.buffer_minutes' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'preferences.teleconsultations_enabled' => ['sometimes', 'boolean'],
            'preferences.auto_confirm_appointments' => ['sometimes', 'boolean'],
            'preferences.notifications' => ['sometimes', 'array:results_pending,new_messages,follow_up_reminders,epidemiology_alerts,upcoming_teleconsultations'],
            'preferences.notifications.*' => ['boolean'],
            'preferences.clinical' => ['sometimes', 'array:show_relevant_history,show_epidemiology'],
            'preferences.clinical.*' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $workingHours = $this->input('preferences.working_hours', []);
            if (! is_array($workingHours)) {
                return;
            }

            foreach ($workingHours as $day => $hours) {
                if (! is_array($hours) || ($hours['enabled'] ?? false) !== true) {
                    continue;
                }

                if (! isset($hours['start'], $hours['end']) || (string) $hours['start'] >= (string) $hours['end']) {
                    $validator->errors()->add(
                        "preferences.working_hours.{$day}",
                        'La hora de inicio debe ser anterior a la hora de cierre.',
                    );
                }
            }
        }];
    }
}
