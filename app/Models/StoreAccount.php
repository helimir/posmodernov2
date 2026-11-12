<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El vinculo entre una cuenta y una tienda: el ROL que la cuenta cumple en el
 * plan de ESA tienda.
 *
 * El rol es del vinculo, no de la cuenta: la misma cuenta puede ser la caja
 * en una tienda y no cumplir ningun rol en otra.
 *
 * `role` es un enum de Postgres (`account_role`), y el vinculo tiene las dos
 * unicidades del plan: una tienda tiene una cuenta por rol, y una cuenta
 * cumple un rol por tienda.
 */
class StoreAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'store_id',
        'role',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'store_id' => 'integer',
            'role' => 'string',
            'status' => 'string',
            'sort_order' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
