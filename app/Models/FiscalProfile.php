<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class FiscalProfile extends Model
{
    use HasFactory;

    /**
     * Conjunto cerrado del modelo de firma. Todavía no es un enum de PHP: los
     * enums genéricos del dominio fiscal son de 0.2.5 (PR siguiente) y este
     * conjunto se sustituye por `SignerModel::cases()` sin cambiar el contrato.
     */
    public const SIGNER_MODELS = ['authority', 'psp', 'printer', 'legal_signature'];

    protected $fillable = [
        'country_id', 'code', 'name', 'document_standard', 'schema_version',
        'schema_reference_url', 'xml_namespace', 'xsd_url', 'id_scheme_code',
        'id_scheme_pattern', 'authority_reference_scheme', 'numbering_scheme',
        'requires_control_number', 'authorized_range_required', 'holes_allowed',
        'signer_model', 'hash_algorithm', 'signature_scheme', 'rounding_rule',
        'tax_inclusive_default', 'default_currency_id',
        'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'country_id' => 'integer',
            'default_currency_id' => 'integer',
            'requires_control_number' => 'boolean',
            'authorized_range_required' => 'boolean',
            'holes_allowed' => 'boolean',
            'tax_inclusive_default' => 'boolean',
            'signer_model' => 'string',
            'hash_algorithm' => 'string',
            'signature_scheme' => 'string',
            'rounding_rule' => 'string',
            'authority_reference_scheme' => 'string',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            if (! in_array($profile->signer_model, self::SIGNER_MODELS, true)) {
                throw new InvalidArgumentException(
                    'signer_model must be one of: '.implode(', ', self::SIGNER_MODELS)
                );
            }
        });
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency_id');
    }

    public function documentTypes(): HasMany
    {
        return $this->hasMany(FiscalProfileDocumentType::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(FiscalProfileEnvironment::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FiscalProfileField::class);
    }

    public function taxSchemes(): HasMany
    {
        return $this->hasMany(FiscalTaxScheme::class);
    }

    public function statusVocabularies(): HasMany
    {
        return $this->hasMany(FiscalStatusVocabulary::class);
    }

    public function providers(): HasMany
    {
        return $this->hasMany(FiscalProvider::class);
    }
}
