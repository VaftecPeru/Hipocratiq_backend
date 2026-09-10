<?php

namespace App\Models;

use Database\Factories\ClinicReceptionistSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['clinic_id', 'user_id', 'preferences'])]
class ClinicReceptionistSetting extends Model
{
    /** @use HasFactory<ClinicReceptionistSettingFactory> */
    use HasFactory;

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['preferences' => 'array'];
    }
}
