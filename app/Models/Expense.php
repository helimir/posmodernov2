<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El gasto: la FUENTE DE VERDAD del gasto.
 *
 * El gasto nace aqui y su asiento contable se deriva de el. El legacy hacia
 * al reves (el movimiento bancario creaba el gasto), y por eso un gasto
 * registrado sin movimiento bancario se perdia y un movimiento bancario sin
 * gasto inventaba uno.
 *
 * `business_date` es la FECHA DE NEGOCIO y es un dato, no un calculo.
 *
 * Y EL GASTO SE ANULA, NO SE BORRA: `status` pasa a `cancelled` con su
 * motivo, su instante y su responsable, y el asiento del ajuste lo deja todo
 * como estaba. Una fuente de verdad que se borra deja al libro contable con
 * un movimiento sin hecho que lo respalde.
 *
 * Y `payment_type` dice DE DONDE se pago el gasto: la caja o una cuenta
 * bancaria. Es lo que decide que cuenta se acredita en el asiento, y por eso
 * es la regla que separa el gasto de la caja del gasto del banco.
 */
class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'expense_category_id',
        'amount',
        'description',
        'reference',
        'business_date',
        'incurred_at',
        'payment_type',
        'bank_account_id',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'expense_category_id' => 'integer',
            'created_by' => 'integer',
            'cancelled_by' => 'integer',
            'bank_account_id' => 'integer',
            'amount' => 'decimal:4',
            'description' => 'string',
            'reference' => 'string',
            'business_date' => 'date',
            'incurred_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancellation_reason' => 'string',
            'payment_type' => 'string',
            'status' => 'string',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Quien ANULO el gasto.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * La cuenta bancaria que PAGA el gasto, cuando se paga desde el banco.
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
