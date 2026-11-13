<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiagnosticClinicAffiliation extends Model
{
    protected $guarded = ['*'];

    public function diagnosticProvider(): BelongsTo
    {
        return $this->belongsTo(DiagnosticProvider::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }
}
