<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    use HasFactory;

    /**
     * El TIPO del medio de pago de CREDITO.
     *
     * Un pago con un metodo de este tipo NO es un cobro: es DEUDA del
     * cliente, y por eso no genera movimiento de pago en su libro.
     */
    public const TYPE_CREDIT = 'credit';

    protected $fillable = [
        'code',
        'name',
        'type',
        'country_rules',
    ];

    protected function casts(): array
    {
        return [
            'country_rules' => 'array',
            'type' => 'string',
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(PaymentMethodStore::class, 'payment_method_id');
    }
}
