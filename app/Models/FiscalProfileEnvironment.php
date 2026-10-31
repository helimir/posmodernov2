<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class FiscalProfileEnvironment extends Model
{
    use HasFactory;

    /**
     * Conjunto cerrado del papel del despliegue. Los enums genéricos del
     * dominio fiscal son de 0.2.5 (PR siguiente).
     */
    public const ROLES = ['issuer', 'printer'];

    protected $fillable = [
        'fiscal_profile_id', 'code', 'label', 'role', 'provider_id', 'base_url',
        'auth_scheme', 'is_testing', 'is_production',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_profile_id' => 'integer',
            'provider_id' => 'integer',
            'role' => 'string',
            'is_testing' => 'boolean',
            'is_production' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $environment): void {
            if (! in_array($environment->role, self::ROLES, true)) {
                throw new InvalidArgumentException(
                    'role must be one of: '.implode(', ', self::ROLES)
                );
            }
        });
    }

    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(FiscalProvider::class);
    }
}
