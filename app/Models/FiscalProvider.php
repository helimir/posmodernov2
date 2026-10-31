<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class FiscalProvider extends Model
{
    use HasFactory;

    /**
     * Conjunto cerrado del actor intermedio. Los enums genéricos del dominio
     * fiscal son de 0.2.5 (PR siguiente).
     */
    public const KINDS = ['printer', 'pac', 'portal'];

    protected $fillable = [
        'fiscal_profile_id', 'code', 'name', 'kind', 'base_url', 'auth_scheme',
        'credentials_ref', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_profile_id' => 'integer',
            'kind' => 'string',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $provider): void {
            if (! in_array($provider->kind, self::KINDS, true)) {
                throw new InvalidArgumentException(
                    'kind must be one of: '.implode(', ', self::KINDS)
                );
            }
        });
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(FiscalProfileEnvironment::class);
    }
}
