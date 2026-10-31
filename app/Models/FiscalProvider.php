<?php

namespace App\Models;

use App\Domain\Fiscal\Enums\ProviderKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class FiscalProvider extends Model
{
    use HasFactory;

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
            if (ProviderKind::tryFrom((string) $provider->kind) === null) {
                throw new InvalidArgumentException(
                    'kind must be one of: '.implode(', ', array_column(ProviderKind::cases(), 'value'))
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
