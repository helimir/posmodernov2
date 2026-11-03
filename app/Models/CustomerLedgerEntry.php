<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLedgerEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'store_id',
        'type',
        'reference_no',
        'ref_invoice_id',
        'amount',
        'balance_after',
        'entry_date',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'balance_after' => 'decimal:4',
            'entry_date' => 'date',
            'type' => 'string',
            'customer_id' => 'integer',
            'store_id' => 'integer',
            'ref_invoice_id' => 'integer',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'ref_invoice_id');
    }
}
