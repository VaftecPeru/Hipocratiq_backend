<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'provider_type',
    'name',
    'tax_id',
    'country_code',
    'contact_name',
    'contact_email',
    'contact_phone',
])]
class DiagnosticProvider extends Model
{
    // status, verified_at y verified_by_user_id nunca se aceptan por mass assignment.
    public function memberships(): HasMany
    {
        return $this->hasMany(DiagnosticProviderMembership::class);
    }

    public function clinicAffiliations(): HasMany
    {
        return $this->hasMany(DiagnosticClinicAffiliation::class);
    }
}
