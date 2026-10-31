<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalProfileField extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_profile_id', 'key', 'data_type', 'label', 'value', 'required',
        'applies_to', 'evidence_status', 'source_ref',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_profile_id' => 'integer',
            'key' => 'string',
            'value' => 'string',
            'required' => 'boolean',
            'evidence_status' => 'string',
            'source_ref' => 'string',
        ];
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }
}
