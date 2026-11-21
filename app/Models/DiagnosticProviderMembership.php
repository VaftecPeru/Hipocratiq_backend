<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiagnosticProviderMembership extends Model
{
    protected $guarded = ['*'];

    public function diagnosticProvider(): BelongsTo
    {
        return $this->belongsTo(DiagnosticProvider::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
