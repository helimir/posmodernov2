<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'iso_code', 'name', 'locale', 'timezone', 'default_currency_id',
        'phone_format', 'document_id_format',
    ];

    protected function casts(): array
    {
        return [
            'default_currency_id' => 'integer',
            'iso_code' => 'string',
        ];
    }

    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id');
    }

    public function numberFormat(): HasOne
    {
        return $this->hasOne(NumberFormat::class);
    }

    public function fiscalProfiles(): HasMany
    {
        return $this->hasMany(FiscalProfile::class);
    }
}
