<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'title', 'description', 'sort_order', 'content_schema', 'is_active'])]
class ClinicalHistorySection extends Model
{
    use HasFactory;

    public function entries(): HasMany
    {
        return $this->hasMany(ClinicalHistoryEntry::class);
    }

    protected function casts(): array
    {
        return [
            'content_schema' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
