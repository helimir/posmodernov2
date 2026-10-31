<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalProfileDocumentType extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_profile_id', 'code', 'name', 'direction', 'tax_treatment',
        'requires_authorized_range', 'allows_cancellation', 'requires_parent_reference',
        'exemption_marker',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_profile_id' => 'integer',
            'tax_treatment' => 'string',
            'requires_authorized_range' => 'boolean',
            'allows_cancellation' => 'boolean',
            'requires_parent_reference' => 'boolean',
            'exemption_marker' => 'string',
        ];
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }
}
