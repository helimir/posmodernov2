<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalStatusVocabulary extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_profile_id', 'native_code', 'native_label', 'generic_status',
        'is_final', 'is_cancellation',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_profile_id' => 'integer',
            'native_code' => 'string',
            'native_label' => 'string',
            'generic_status' => 'string',
            'is_final' => 'boolean',
            'is_cancellation' => 'boolean',
        ];
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }
}
