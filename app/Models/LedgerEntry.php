<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La cabecera de un asiento contable.
 *
 * `business_date` es la FECHA DE NEGOCIO y es un dato, no un calculo: el
 * legacy filtraba por `DAY()/MONTH()/YEAR()` calculados (trampa 62).
 */
class LedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'entry_type_id',
        'store_id',
        'business_date',
        'reference_type',
        'reference_id',
        'amount',
        'occurred_at',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_type_id' => 'integer',
            'store_id' => 'integer',
            'reference_id' => 'integer',
            'created_by' => 'integer',
            'business_date' => 'date',
            'occurred_at' => 'datetime',
            'amount' => 'decimal:4',
        ];
    }

    public function entryType(): BelongsTo
    {
        return $this->belongsTo(LedgerEntryType::class, 'entry_type_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class, 'ledger_entry_id');
    }
}
