<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'product_id',
        'store_id',
        'quantity',
        'unit_price',
        'discount',
        'tax_category_id',
        'tax_rate_id',
        'tax_rate_snapshot',
        'tax_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax_rate_snapshot' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'sale_id' => 'integer',
            'product_id' => 'integer',
            'store_id' => 'integer',
            'tax_category_id' => 'integer',
            'tax_rate_id' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
