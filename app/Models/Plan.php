<?php

namespace App\Models;

use App\Enums\PlanKey;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name', 'monthly_price_cents', 'annual_price_cents', 'currency', 'features', 'publicly_selectable', 'is_active'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ClinicSubscription::class);
    }

    protected function casts(): array
    {
        return [
            'key' => PlanKey::class,
            'features' => 'array',
            'publicly_selectable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
