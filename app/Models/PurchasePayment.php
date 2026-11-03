<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchasePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_id',
        'payment_method_id',
        'invoice_id',
        'amount',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'details' => 'array',
            'purchase_id' => 'integer',
            'payment_method_id' => 'integer',
            'invoice_id' => 'integer',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'invoice_id');
    }
}
