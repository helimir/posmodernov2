<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El bridge de la unidad de medida con la tienda.
 *
 * La tabla maestra `units` guarda la IDENTIDAD de la unidad; este bridge
 * guarda su ESTADO POR TIENDA. La tabla la creo M09.
 */
class UnitStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'unit_id',
        'store_id',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'unit_id' => 'integer',
            'store_id' => 'integer',
            'status' => 'string',
            'sort_order' => 'integer',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
