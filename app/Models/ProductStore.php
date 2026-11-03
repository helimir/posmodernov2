<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'store_id',
        'tax_category_id',
        'purchase_price',
        'sell_price',
        'quantity_in_stock',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:4',
            'sell_price' => 'decimal:4',
            'quantity_in_stock' => 'decimal:4',
            'sort_order' => 'integer',
            'product_id' => 'integer',
            'store_id' => 'integer',
        ];
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
