<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un saldo de apertura: el punto de partida que se CARGA A MANO y se
 * CERTIFICA antes de arrancar.
 *
 * `kind` dice de que saldo se trata (`stock`, `customer_balance`,
 * `supplier_balance`, `cash`) y el esquema lo vigila: el `kind` exige SU FK
 * y prohibe las otras tres.
 *
 * `certified_at` y `certified_by` son la CERTIFICACION. Sin ellos el saldo no
 * vale: `ops:check-opening-balances` (0.7.6) falla mientras haya saldos sin
 * certificar. Los certifica una persona de direccion financiera, con fecha y
 * responsable nominal, porque un saldo contable no lo aprueba un script.
 */
class OpeningBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'kind',
        'product_id',
        'customer_id',
        'supplier_id',
        'bank_account_id',
        'amount',
        'as_of',
        'certified_at',
        'certified_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'product_id' => 'integer',
            'customer_id' => 'integer',
            'supplier_id' => 'integer',
            'bank_account_id' => 'integer',
            'certified_by' => 'integer',
            'amount' => 'decimal:4',
            'as_of' => 'date',
            'certified_at' => 'datetime',
            'kind' => 'string',
            'note' => 'string',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function certifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }
}
