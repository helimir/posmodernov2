<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'mobile',
        'address',
        'city',
        'zip_code',
        'customer_country',
        'customer_state',
        'tax_id',
        'credit_limit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:4',
            'is_active' => 'boolean',
            'customer_country' => 'integer',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'customer_country');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(CustomerStore::class, 'customer_id');
    }
}
