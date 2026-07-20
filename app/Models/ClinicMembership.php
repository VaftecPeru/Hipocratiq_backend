<?php

namespace App\Models;

use App\Enums\ClinicMembershipStatus;
use Database\Factories\ClinicMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['clinic_id', 'user_id', 'role_id', 'status'])]
class ClinicMembership extends Model
{
    /** @use HasFactory<ClinicMembershipFactory> */
    use HasFactory;

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    protected function casts(): array
    {
        return ['status' => ClinicMembershipStatus::class];
    }
}
