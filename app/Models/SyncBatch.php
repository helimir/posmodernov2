<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un LOTE de sincronizacion aplicado.
 *
 * La fila es la CLAVE DE IDEMPOTENCIA del maestro: (`client_id`,
 * `payload_sha256`) es UNIQUE, asi que un REENVIO del mismo lote se
 * reconoce y se responde con exito SIN volver a aplicarlo. Sin esta
 * restriccion, el terminal legacy que perdio la respuesta reenvia el lote y
 * se queda congelado en la ventana de corte (ADR-0006, hallazgo S1).
 *
 * `payload_sha256` es el hash del LOTE (el string `data` del contrato), NO
 * el del cuerpo HTTP: el terminal reenvia el mismo lote con un `nonce` y un
 * `timestamp` nuevos.
 */
class SyncBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'payload_sha256',
        'statement_count',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_count' => 'integer',
            'applied_at' => 'datetime',
        ];
    }
}
