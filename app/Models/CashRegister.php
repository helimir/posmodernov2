<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La caja de una tienda en una fecha de negocio.
 *
 * `business_date` es la FECHA DE NEGOCIO y es un dato, no un calculo: el
 * legacy filtraba por `DAY()/MONTH()/YEAR()` calculados (trampa 62).
 *
 * `opening_balance` es el saldo de PARTIDA, congelado. `closing_balance` es
 * lo que se observo al cerrar. El saldo EN CURNO no esta aqui: se deriva de
 * los asientos de esa fecha de negocio.
 *
 * `opened_by` y `closed_by` son los RESPONSABLES de la apertura y del
 * cierre: los dos pueden faltar (`null`), y cuando faltan el hueco se ve.
 */
class CashRegister extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'business_date',
        'bank_account_id',
        'opened_at',
        'closed_at',
        'opening_balance',
        'closing_balance',
        'status',
        'opened_by',
        'closed_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'bank_account_id' => 'integer',
            'opened_by' => 'integer',
            'closed_by' => 'integer',
            'business_date' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_balance' => 'decimal:4',
            'closing_balance' => 'decimal:4',
            'status' => 'string',
            'note' => 'string',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
