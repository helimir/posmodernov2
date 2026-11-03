<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'country_rules',
    ];

    protected function casts(): array
    {
        return [
            'country_rules' => 'array',
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(PaymentMethodStore::class, 'payment_method_id');
    }
}
