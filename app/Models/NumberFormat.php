<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NumberFormat extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_id', 'date_format', 'time_format', 'am_pm',
        'invoice_number_format', 'phone_format', 'document_id_format',
    ];

    protected function casts(): array
    {
        return [
            'country_id' => 'integer',
            'invoice_number_format' => 'string',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
