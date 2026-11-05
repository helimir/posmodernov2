<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El bridge del metodo de pago con la tienda.
 *
 * La tabla maestra `payment_methods` guarda la IDENTIDAD del metodo de pago
 * (y sus reglas por pais); este bridge guarda su ESTADO POR TIENDA. La tabla
 * la creo M09.
 */
class PaymentMethodStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_method_id',
        'store_id',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'payment_method_id' => 'integer',
            'store_id' => 'integer',
            'status' => 'string',
            'sort_order' => 'integer',
        ];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
