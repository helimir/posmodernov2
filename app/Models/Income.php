<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El ingreso: la FUENTE DE VERDAD del ingreso.
 *
 * Es la SIMETRIA de `expenses`: un ingreso registrado sin movimiento
 * bancario sigue siendo ingreso, y un movimiento bancario sin ingreso no
 * inventa ninguno. El asiento es la consecuencia, no el hecho.
 *
 * `business_date` es la FECHA DE NEGOCIO y es un dato, no un calculo. Y
 * `payment_type` dice DONDE entra el dinero: la caja o una cuenta bancaria.
 *
 * Y EL INGRESO SE ANULA, NO SE BORRA: `status` pasa a `cancelled` con su
 * motivo, su instante y su responsable.
 */
class Income extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'income_source_id',
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
            'income_source_id' => 'integer',
            'bank_account_id' => 'integer',
            'created_by' => 'integer',
            'cancelled_by' => 'integer',
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

    public function source(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class, 'income_source_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Quien ANULO el ingreso.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * La cuenta bancaria en la que ENTRA el dinero, cuando entra en el banco.
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
