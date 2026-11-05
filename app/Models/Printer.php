<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El catálogo de impresoras.
 *
 * La IDENTIDAD del modelo de impresora vive aquí; el estado por tienda vive
 * en `print_targets`. Un mismo modelo puede atender a varias tiendas.
 */
class Printer extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PrintTarget::class, 'printer_id');
    }
}
