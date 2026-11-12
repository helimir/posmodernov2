<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una CUENTA del catalogo contable.
 *
 * La cuenta es la IDENTIDAD: su codigo (que es su clave natural y UNIQUE), su
 * nombre y su TIPO contable. Lo que la cuenta hace en una tienda NO esta
 * aqui: es el rol que cumple en el plan de ESA tienda, y vive en
 * `store_accounts`.
 *
 * El `type` es un enum de Postgres (`account_type`): el conjunto es cerrado y
 * lo hace cumplir la base.
 */
class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'string',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Los PLANES en los que la cuenta cumple un rol.
     */
    public function stores(): HasMany
    {
        return $this->hasMany(StoreAccount::class, 'account_id');
    }
}
