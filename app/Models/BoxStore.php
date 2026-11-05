<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El bridge de la caja (unidad de empaque) con la tienda.
 *
 * La tabla maestra `boxes` guarda la IDENTIDAD de la caja; este bridge guarda
 * su ESTADO POR TIENDA. La tabla la creo M09.
 */
class BoxStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'box_id',
        'store_id',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'box_id' => 'integer',
            'store_id' => 'integer',
            'status' => 'string',
            'sort_order' => 'integer',
        ];
    }

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class, 'box_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
