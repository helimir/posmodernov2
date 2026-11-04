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
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'expense_category_id' => 'integer',
            'created_by' => 'integer',
            'amount' => 'decimal:4',
            'description' => 'string',
            'reference' => 'string',
            'business_date' => 'date',
            'incurred_at' => 'datetime',
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
}
