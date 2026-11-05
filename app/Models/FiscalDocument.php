<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El comprobante fiscal: la cabecera del documento.
 *
 * El estado es un enum GENERICO (`draft`, `issued`, `void`) y el codigo nativo
 * del pais vive en `native_status_code`: la tabla la creo M22 y M31 le
 * cerro la FK del receptor.
 *
 * Solo se declaran las relaciones que apuntan a modelos que EXISTEN: la serie
 * (`FiscalSeries`), el control, los impuestos y los eventos todavia no tienen
 * modelo, y declararlas seria una relacion a una clase inexistente.
 */
class FiscalDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_profile_id',
        'store_id',
        'provider_id',
        'series_id',
        'number',
        'reservation_id',
        'document_type',
        'status',
        'native_status_code',
        'authority_reference',
        'authority_reference_scheme',
        'qr_content',
        'qr_url',
        'hash_algorithm',
        'signature_scheme',
        'hash',
        'printer_validation_status',
        'customer_id',
        'currency_id',
        'original_currency_id',
        'exchange_rate',
        'subtotal',
        'tax_total',
        'total_amount',
        'issue_date',
        'issued_at',
        'voided_at',
        'period_from',
        'period_to',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_profile_id' => 'integer',
            'store_id' => 'integer',
            'provider_id' => 'integer',
            'series_id' => 'integer',
            'number' => 'integer',
            'reservation_id' => 'integer',
            'customer_id' => 'integer',
            'currency_id' => 'integer',
            'original_currency_id' => 'integer',
            'exchange_rate' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'document_type' => 'string',
            'status' => 'string',
            'native_status_code' => 'string',
            'authority_reference' => 'string',
            'authority_reference_scheme' => 'string',
            'qr_content' => 'string',
            'qr_url' => 'string',
            'hash_algorithm' => 'string',
            'signature_scheme' => 'string',
            'hash' => 'string',
            'printer_validation_status' => 'string',
            'issue_date' => 'date',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'period_from' => 'date',
            'period_to' => 'date',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class, 'fiscal_profile_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(FiscalProvider::class, 'provider_id');
    }
}
