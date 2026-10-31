<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'decimals', 'symbol', 'symbol_position',
        'thousand_separator', 'decimal_separator',
    ];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'code' => 'string',
        ];
    }
}
