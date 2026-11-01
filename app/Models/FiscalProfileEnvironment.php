<?php

namespace App\Models;

use App\Domain\Fiscal\Enums\EnvironmentRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class FiscalProfileEnvironment extends Model
{
    use HasFactory;

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
            if (EnvironmentRole::tryFrom((string) $environment->role) === null) {
                throw new InvalidArgumentException(
                    'role must be one of: '.implode(', ', array_column(EnvironmentRole::cases(), 'value'))
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
