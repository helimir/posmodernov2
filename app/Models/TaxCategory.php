<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría fiscal del producto: el tratamiento (gravable, exento, no gravado,
 * exonerado, IVA retenido). No guarda un porcentaje: el porcentaje vive en la
 * tasa, con vigencia y evidencia propias.
 */
class TaxCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_profile_id', 'code', 'name', 'is_withholding', 'applies_to',
        'evidence_status', 'source_ref',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_profile_id' => 'integer',
            'is_withholding' => 'boolean',
            'evidence_status' => 'string',
            'source_ref' => 'string',
        ];
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }
}
