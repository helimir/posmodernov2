<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'store_id',
        'balance',
        'due',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:4',
            'due' => 'decimal:4',
            'sort_order' => 'integer',
            'supplier_id' => 'integer',
            'store_id' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
