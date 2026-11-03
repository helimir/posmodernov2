<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Box extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
    ];

    protected function casts(): array
    {
        return [
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'box_id');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(BoxStore::class, 'box_id');
    }
}
