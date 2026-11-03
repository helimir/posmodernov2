<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'template_content',
    ];

    protected function casts(): array
    {
        return [
            'template_content' => 'array',
        ];
    }

    public function stores(): HasMany
    {
        return $this->hasMany(PosTemplateStore::class, 'pos_template_id');
    }
}
