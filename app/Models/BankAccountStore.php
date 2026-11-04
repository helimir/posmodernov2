<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El vinculo entre una cuenta bancaria y una tienda.
 *
 * El estado por tienda (activo, orden) es del vinculo, no de la cuenta: la
 * misma cuenta puede estar activa en una tienda y apagada en otra.
 *
 * `opening_balance` es el saldo de partida DE LA CUENTA EN ESA TIENDA: el
 * saldo es por cuenta Y tienda. El saldo en curso se deriva de los asientos.
 */
class BankAccountStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_account_id',
        'store_id',
        'opening_balance',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'bank_account_id' => 'integer',
            'store_id' => 'integer',
            'opening_balance' => 'decimal:4',
            'status' => 'string',
            'sort_order' => 'integer',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
