<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreFiscalRegistration extends Model
{
    use HasFactory;

    protected $fillable = ['store_id', 'tax_id', 'tax_id_scheme', 'issued_at', 'is_primary'];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'issued_at' => 'date',
            'is_primary' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
