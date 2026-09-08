<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['clinical_history_record_id', 'clinical_history_section_id', 'values'])]
class ClinicalHistoryEntry extends Model
{
    use HasFactory;

    public function record(): BelongsTo
    {
        return $this->belongsTo(ClinicalHistoryRecord::class, 'clinical_history_record_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(ClinicalHistorySection::class, 'clinical_history_section_id');
    }

    protected function casts(): array
    {
        return ['values' => 'array'];
    }
}
