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
 *
 * `account_code` es la CUENTA CONTABLE de la cuenta bancaria EN ESA TIENDA:
 * como el saldo es por cuenta y tienda, su cuenta en el libro tambien lo es,
 * y por eso vive en el vinculo y no en el maestro.
 */
class BankAccountStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_account_id',
        'store_id',
        'opening_balance',
        'account_code',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'bank_account_id' => 'integer',
            'store_id' => 'integer',
            'opening_balance' => 'decimal:4',
            'account_code' => 'string',
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
