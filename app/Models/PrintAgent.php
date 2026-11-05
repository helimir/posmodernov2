<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un AGENTE LAN instalado en un terminal.
 *
 * El token del agente se guarda SOLO como hash: el token en claro nunca se
 * persiste, ni en la base ni en el repositorio. Una fuga acota a UNA CAJA, no
 * a una tienda entera (design D5).
 *
 * El UMBRAL de desconexión no es una columna: es un parámetro de
 * `config/print.php` (`agent_disconnect_threshold_seconds`), porque cambia
 * con la operación y no con el esquema.
 */
class PrintAgent extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'name',
        'token_hash',
        'last_heartbeat_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'token_hash' => 'string',
            'last_heartbeat_at' => 'datetime',
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
