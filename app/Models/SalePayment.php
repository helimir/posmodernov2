<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'payment_method_id',
        'amount',
        'applied',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            // Lo APLICADO a la venta: lo que el pago cubre. Es distinto del
            // bruto (`amount`) cuando hay cambio.
            'applied' => 'decimal:4',
            'details' => 'array',
            'sale_id' => 'integer',
            'payment_method_id' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }
}
