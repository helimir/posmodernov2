<?php

namespace App\Models;

use App\Fiscal\Enums\SeriesAuthority;
use App\Fiscal\Enums\SeriesRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * La SERIE fiscal: la numeracion declarada de una tienda.
 *
 * REGLA que fija este modelo: *una serie es una fila, y la fila declara su
 * numeracion*. El perfil dice el regimen, la tienda dice donde se usa, el
 * papel dice que numeracion es y el prefijo dice como se escribe el numero.
 *
 * Y EL CONTADOR (`next_number`) NO SE EDITA: lo mueve el DOMINIO, con la
 * reserva atomica (`NumberSeries`, `UPDATE ... RETURNING`). Un contador
 * escrito a mano es un numero repartido dos veces.
 *
 * Y EL PAPEL Y LA AUTORIDAD SON CONJUNTOS CERRADOS: el modelo los
 * comprueba, como los comprueban los otros modelos del dominio (`kind` del
 * proveedor, `signer_model` del perfil). El esquema tambien los limita, con
 * una restriccion CHECK, asi que la fila y el modelo dicen lo mismo.
 */
class FiscalSeries extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_profile_id', 'store_id', 'provider_id', 'series_role', 'assigned_by',
        'prefix', 'next_number', 'from_number', 'to_number', 'valid_from', 'valid_to',
        'credential_ref', 'control_identifier', 'sequence_length',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_profile_id' => 'integer',
            'store_id' => 'integer',
            'provider_id' => 'integer',
            'next_number' => 'integer',
            'from_number' => 'integer',
            'to_number' => 'integer',
            'sequence_length' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $serie): void {
            if (SeriesRole::tryFrom((string) $serie->series_role) === null) {
                throw new InvalidArgumentException(
                    'series_role must be one of: '.implode(', ', array_column(SeriesRole::cases(), 'value'))
                );
            }

            if (SeriesAuthority::tryFrom((string) $serie->assigned_by) === null) {
                throw new InvalidArgumentException(
                    'assigned_by must be one of: '.implode(', ', array_column(SeriesAuthority::cases(), 'value'))
                );
            }
        });
    }

    /**
     * El PERFIL fiscal: el regimen que reparte la numeracion.
     */
    public function fiscalProfile(): BelongsTo
    {
        return $this->belongsTo(FiscalProfile::class);
    }

    /**
     * La TIENDA donde se usa la serie.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * La IMPRENTA que respalda el rango de control, si la declara.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(FiscalProvider::class);
    }
}
