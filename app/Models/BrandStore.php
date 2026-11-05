<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El bridge de la marca con la tienda.
 *
 * La tabla maestra `brands` guarda la IDENTIDAD de la marca; este bridge
 * guarda su ESTADO POR TIENDA (`status` y `sort_order`). La tabla la creo
 * M09.
 */
class BrandStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'store_id',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'store_id' => 'integer',
            'status' => 'string',
            'sort_order' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
