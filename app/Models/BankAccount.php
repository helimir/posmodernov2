<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La cuenta bancaria: el donde se deposita y se retira.
 *
 * `opening_balance` es el saldo de PARTIDA, congelado. El saldo en curso se
 * DERIVA de los asientos: esta tabla no lleva ningun contador, porque un
 * contador es un saldo que se cuenta dos veces.
 */
class BankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'number',
        'tax_id',
        'is_active',
        'opening_balance',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'string',
            'number' => 'string',
            'tax_id' => 'string',
            'is_active' => 'boolean',
            'opening_balance' => 'decimal:4',
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(BankAccountStore::class, 'bank_account_id');
    }
}
