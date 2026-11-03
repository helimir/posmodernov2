<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
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
            'customer_id' => 'integer',
            'store_id' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
