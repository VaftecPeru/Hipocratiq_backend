<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['default_country_code', 'enabled_country_codes', 'platform_name', 'support_email', 'support_url', 'default_locale', 'enabled_locales', 'ai_configuration', 'emergency_policy', 'emergency_responsibles', 'emergency_published_at', 'emergency_publication'])]
class PlatformSetting extends Model
{
    protected function casts(): array
    {
        return [
            'enabled_country_codes' => 'array',
            'enabled_locales' => 'array',
            'ai_configuration' => 'array',
            'emergency_policy' => 'array',
            'emergency_responsibles' => 'array',
            'emergency_published_at' => 'datetime',
            'emergency_publication' => 'array',
        ];
    }
}
