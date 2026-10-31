<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalTaxScheme extends Model
{
    use HasFactory;

    protected $fillable = ['fiscal_profile_id', 'code', 'name', 'is_withholding'];

    protected function casts(): array
    {
        return [
            'fiscal_profile_id' => 'integer',
            'code' => 'string',
            'is_withholding' => 'boolean',
        ];
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }
}
