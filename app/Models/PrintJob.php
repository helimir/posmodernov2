<?php

namespace App\Models;

use App\Casts\Bytea;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un TRABAJO de impresión.
 *
 * `payload` es el MODELO del ticket (lo que se imprime) y `payload_bytes` son
 * los BYTES ESC/POS ya generados, congelados por el servidor. El agente LAN
 * no interpreta el ticket: los recibe hechos y sólo los escribe (design D11).
 *
 * El estado del trabajo es INDEPENDIENTE del estado del documento fiscal:
 * emitir no depende de imprimir. Una reimpresión es un trabajo NUEVO con
 * `reason = 'reprint'` y `reprint_of_job_id`, sin reservas nuevas.
 *
 * Y `document_kind` dice QUE imprime el trabajo: un COMPROBANTE fiscal o el Z
 * de un cierre de caja. Un Z no se reimprime como un comprobante: se reimprime
 * desde la pantalla de la caja del dia, con un MOTIVO.
 */
class PrintJob extends Model
{
    use HasFactory;

    /**
     * El trabajo imprime un COMPROBANTE fiscal.
     */
    public const KIND_RECEIPT = 'receipt';

    /**
     * El trabajo imprime el Z de un CIERRE de caja.
     *
     * REGLA que fija esta constante: *un Z no es un comprobante*. El Z es el
     * documento del cierre: se reimprime con un MOTIVO, y el trabajo que lo
     * reimprime apunta al Z (`cash_close_id`).
     */
    public const KIND_CASH_CLOSE_Z = 'cash_close_z';

    protected $fillable = [
        'store_id',
        'print_target_id',
        'agent_id',
        'status',
        'document_kind',
        'payload',
        'payload_bytes',
        'payload_sha256',
        'reason',
        'reprint_of_job_id',
        'cash_close_id',
        'lease_expires_at',
        'attempts',
        'last_error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'store_id' => 'integer',
            'print_target_id' => 'integer',
            'agent_id' => 'integer',
            'reprint_of_job_id' => 'integer',
            'cash_close_id' => 'integer',
            'created_by' => 'integer',
            'status' => 'string',
            'document_kind' => 'string',
            'payload' => 'array',
            // El `bytea` de PostgreSQL vuelve como un flujo: sin este cast,
            // `(string) $flujo` devolvería "Resource id #525" y los bytes del
            // ticket se perderían en silencio.
            'payload_bytes' => Bytea::class,
            'payload_sha256' => 'string',
            'reason' => 'string',
            'attempts' => 'integer',
            'lease_expires_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(PrintTarget::class, 'print_target_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(PrintAgent::class, 'agent_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function reprintOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reprint_of_job_id');
    }

    /**
     * ¿El trabajo imprime el Z de un cierre de caja?
     */
    public function esCierreDeCaja(): bool
    {
        return (string) $this->document_kind === self::KIND_CASH_CLOSE_Z;
    }
}
