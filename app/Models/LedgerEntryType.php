<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El catalogo de tipos de asiento contable.
 *
 * Sustituye las veintiuna banderas booleanas del legacy: un movimiento
 * contable tiene UN tipo, y el tipo se consulta aqui.
 */
class LedgerEntryType extends Model
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
            'code' => 'string',
            'name' => 'string',
            'is_active' => 'boolean',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'entry_type_id');
    }
}
