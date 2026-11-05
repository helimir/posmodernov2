<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El bridge de la plantilla del POS con la tienda.
 *
 * La tabla maestra `pos_templates` guarda la IDENTIDAD y el contenido de la
 * plantilla; este bridge guarda su ESTADO POR TIENDA. La tabla la creo M09.
 */
class PosTemplateStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'pos_template_id',
        'store_id',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'pos_template_id' => 'integer',
            'store_id' => 'integer',
            'status' => 'string',
            'sort_order' => 'integer',
        ];
    }

    public function posTemplate(): BelongsTo
    {
        return $this->belongsTo(PosTemplate::class, 'pos_template_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
