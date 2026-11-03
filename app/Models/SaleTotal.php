<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleTotal extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'sub_total',
        'discount',
        'tax_total',
        'rounding_adjustment',
        'grand_total',
    ];

    protected function casts(): array
    {
        return [
            'sub_total' => 'decimal:4',
            'discount' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'rounding_adjustment' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'sale_id' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }
}
