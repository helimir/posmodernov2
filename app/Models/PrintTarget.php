<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un DESTINO de impresión: una impresora concreta en una tienda concreta.
 *
 * El perfil de impresora es DATO, no código (design D5): el destino declara
 * qué sabe hacer la impresora (codepágina, ancho, soporte de QR, límite del
 * payload del QR) y el servidor consulta esas capacidades antes de imprimir.
 *
 * El transporte `browser` no tiene endpoint: renderiza HTML/PDF desde el
 * MISMO modelo de plantilla. Los otros dos escriben bytes en un destino
 * físico, y el USB directo no es un destino admitido.
 */
class PrintTarget extends Model
{
    use HasFactory;

    /**
     * Los TRANSPORTES que escriben bytes en un destino FISICO.
     *
     * El transporte `browser` no esta: no tiene endpoint, porque el
     * navegador ES el destino, y el servidor lo que hace es renderizar el
     * HTML del mismo modelo de plantilla.
     */
    public const TRANSPORTES_FISICOS = ['lan_agent', 'server_socket'];

    protected $fillable = [
        'printer_id',
        'store_id',
        'name',
        'transport',
        'endpoint',
        'printer_profile',
        'char_per_line',
        'charset',
        'max_qr_payload_bytes',
        'supports_qr',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'printer_id' => 'integer',
            'store_id' => 'integer',
            'transport' => 'string',
            'endpoint' => 'string',
            'printer_profile' => 'string',
            'char_per_line' => 'integer',
            'charset' => 'string',
            'max_qr_payload_bytes' => 'integer',
            'supports_qr' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'printer_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
