<?php

namespace App\Models;

use App\Domain\Fiscal\Enums\RoundingRule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Tasa aplicable a una categoría, con vigencia. `evidence_status` y
 * `source_ref` declaran el estado del dato: una tasa no confirmada se marca
 * como pendiente, no se afirma.
 */
class TaxRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'tax_category_id', 'percentage', 'rounding_rule', 'valid_from', 'valid_to',
        'priority', 'evidence_status', 'source_ref',
    ];

    protected function casts(): array
    {
        return [
            'tax_category_id' => 'integer',
            'percentage' => 'decimal:6',
            'rounding_rule' => 'string',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'priority' => 'integer',
            'evidence_status' => 'string',
            'source_ref' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $rate): void {
            // Nulo = la tasa hereda la regla de redondeo del perfil fiscal.
            if ($rate->rounding_rule !== null && RoundingRule::tryFrom($rate->rounding_rule) === null) {
                throw new InvalidArgumentException(
                    'rounding_rule must be one of: '.implode(', ', array_column(RoundingRule::cases(), 'value'))
                );
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class, 'tax_category_id');
    }
}
