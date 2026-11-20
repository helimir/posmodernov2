<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un AGENTE LAN instalado en un terminal.
 *
 * El token del agente se guarda SOLO como hash de CONTRASENA (argon2id): el
 * token en claro nunca se persiste, ni en la base ni en el repositorio. Una
 * fuga acota a UNA CAJA, no a una tienda entera (design D5).
 *
 * El `instance_id` es el identificador ESTABLE de la instalacion: al
 * registrarse con uno que ya existe en la tienda, la fila se REEMPLAZA y el
 * token anterior deja de valer. Es lo que detecta un terminal reinstalado.
 *
 * La `revoked_at` es la revocacion DEFINITIVA: `is_active` es un interruptor,
 * esto es una sentence.
 *
 * El UMBRAL de desconexión no es una columna: es un parámetro de
 * `config/print.php` (`agent_disconnect_threshold_seconds`), porque cambia con
 * la operación y no con el esquema.
 */
class PrintAgent extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'instance_id',
        'token_hash',
        'token_hash_previo',
        'token_previo_valido_hasta',
        'last_heartbeat_at',
        'revoked_at',
        'printers',
        'capabilities',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'instance_id' => 'string',
            'token_hash' => 'string',
            'token_hash_previo' => 'string',
            'token_previo_valido_hasta' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'revoked_at' => 'datetime',
            'printers' => 'array',
            'capabilities' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(PrintJob::class, 'agent_id');
    }
}
