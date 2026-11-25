<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El catalogo de origenes de ingreso.
 *
 * NO lleva las once banderas booleanas del legacy: un ingreso no se clasifica
 * con booleanos, se clasifica con su tipo de asiento, que se consulta en
 * `ledger_entry_types`. El ingreso se enlaza a su asiento por
 * `ledger_entries.reference_type` / `reference_id`.
 *
 * `is_active` es de INTERFAZ (permitir ocultar un origen), no un flag
 * contable.
 *
 * Y `account_code` es la CUENTA CONTABLE en la que se abona el ingreso: un
 * DATO del negocio, como los roles del plan de cuentas.
 */
class IncomeSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'parent_id',
        'account_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'code' => 'string',
            'name' => 'string',
            'parent_id' => 'integer',
            'account_code' => 'string',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(IncomeSource::class, 'parent_id');
    }
}
